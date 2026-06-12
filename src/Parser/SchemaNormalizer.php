<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

/**
 * Pre-parse normalisation of the raw decoded spec data, applied before the
 * vendored cebe parser instantiates its object model.
 *
 * Why this exists (issue #20): cebe registers `items` as an always-instantiated
 * Schema attribute, so a boolean `items` value (valid OpenAPI 3.1) makes it call
 * `new Schema(false)` / `new Schema(true)` and blow up with "Unable to
 * instantiate Schema Object with data ''". Boolean `additionalProperties` is
 * fine because cebe special-cases that path, but boolean `items` is not.
 *
 * OpenAPI 3.1 allows:
 *   - `items: true`  , every (extra) item may be anything.
 *   - `items: false` , no items beyond the `prefixItems` tuple are allowed
 *                      (the canonical "closed tuple" spelling).
 *
 * We rewrite both to a form cebe accepts while staying faithful enough for the
 * generator:
 *   - `items: true`  -> `items: {}`  (an empty schema, meaning "any"), which is
 *                       the exact JSON-Schema equivalent of `true`.
 *   - `items: false` -> drop the key. A closed tuple's "no additional items"
 *                       constraint has no Laravel representation anyway (the
 *                       generator types tuples from `prefixItems`), so dropping
 *                       it loses nothing the emitter could have used, and avoids
 *                       inventing a fake "match nothing" schema.
 *
 * It also coerces a second class of slightly-off real-world specs (issues #32
 * and #33): a scalar keyword emitted as a JSON STRING where a number or boolean
 * is expected. Some tools serialise `"minimum": "8"` or `"nullable": "true"`.
 * The downstream emitter reads `$schema->minimum` with `is_int`/`is_float`
 * checks and `$schema->nullable === true`, so a string value is silently
 * dropped: no `min:` rule, no nullable flag. We coerce the unambiguous cases up
 * front so both rule derivation and the nullable flag see proper types:
 *   - numeric keywords (minimum, maximum, exclusiveMinimum/Maximum, multipleOf,
 *     minLength, maxLength, minItems, maxItems, minProperties, maxProperties)
 *     whose value is a STRICTLY-NUMERIC string are cast to int/float. A
 *     non-numeric string (e.g. "8abc") is left untouched and ignored exactly as
 *     before, never coerced and never crashing.
 *   - a `nullable` value of the string "true"/"false" (case-insensitive) is cast
 *     to the matching boolean. Any other string is left untouched (treated as
 *     not-nullable, as before).
 *
 * The walk is recursive and structural: it rewrites every matching key it finds
 * regardless of nesting (arrays of arrays, items inside composition members,
 * keys under component schemas, etc.). It deliberately does not try to
 * understand the surrounding schema; these keys only ever carry their OpenAPI
 * meaning, so a blunt key-based rewrite is both correct and cheap.
 *
 * @internal
 */
final class SchemaNormalizer
{
    /**
     * Numeric schema keywords whose value is a number in OpenAPI. A strictly
     * numeric string here is coerced to int/float so the emitter's numeric
     * checks see it. `multipleOf` is float-capable (0.5); integer-only keywords
     * (lengths, counts) still coerce through the same numeric cast, which yields
     * an int for an integer-valued string.
     *
     * @var list<string>
     */
    private const NUMERIC_KEYS = [
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'minLength',
        'maxLength',
        'minItems',
        'maxItems',
        'minProperties',
        'maxProperties',
    ];

    /**
     * Recursively normalise the decoded spec data before cebe parses it.
     *
     * @param  mixed  $data  the raw decoded spec (associative arrays + scalars)
     * @return mixed the same structure with the rewrites described in the class docblock
     */
    public static function normalize(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if ($key === 'items' && is_bool($value)) {
                if ($value === true) {
                    // `items: true` -> `items: {}` (an empty schema = "any").
                    $result[$key] = [];
                }

                // `items: false` -> drop the key entirely (see class docblock).
                continue;
            }

            // A numeric keyword carried as a strictly-numeric string ("8") is
            // coerced to the proper number so the emitter's is_int/is_float
            // checks fire. A non-numeric string ("8abc") is left as-is and
            // stays ignored downstream. Booleans (3.0 boolean exclusiveMinimum)
            // and already-numeric values pass through untouched.
            if (in_array($key, self::NUMERIC_KEYS, true) && is_string($value) && is_numeric($value)) {
                $result[$key] = self::numericFromString($value);

                continue;
            }

            // A `nullable` carried as the string "true"/"false" is coerced to the
            // matching boolean (case-insensitive). Any other string is left
            // untouched and treated as not-nullable downstream.
            if ($key === 'nullable' && is_string($value)) {
                $lower = strtolower($value);
                if ($lower === 'true') {
                    $result[$key] = true;

                    continue;
                }
                if ($lower === 'false') {
                    $result[$key] = false;

                    continue;
                }
            }

            $result[$key] = self::normalize($value);
        }

        return $result;
    }

    /**
     * Cast a strictly-numeric string to int when it has no fractional part and
     * fits an int, else to float. Mirrors how a JSON number would have decoded:
     * `"8"` -> int 8, `"0.5"` -> float 0.5, so the emitter's int and float rule
     * branches both behave exactly as for a native number.
     */
    private static function numericFromString(string $value): int|float
    {
        $float = (float) $value;

        // An integer-shaped string (no decimal point, no exponent) that fits the
        // platform int range becomes an int; a fractional value, an exponent, or
        // an out-of-range magnitude (e.g. an int64 bound past PHP_INT_MAX) stays
        // a float. The range is checked before any int cast, and the cast is done
        // on the string, not the float: casting an out-of-range float to int both
        // warns ("not representable as an int") and truncates.
        if (
            ! str_contains($value, '.')
            && stripos($value, 'e') === false
            && $float >= (float) PHP_INT_MIN
            && $float <= (float) PHP_INT_MAX
        ) {
            return (int) $value;
        }

        return $float;
    }
}
