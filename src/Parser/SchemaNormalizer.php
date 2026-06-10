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
 * The walk is recursive and structural: it rewrites every `items` key it finds
 * regardless of nesting (arrays of arrays, items inside composition members,
 * items under component schemas, etc.). It deliberately does not try to
 * understand the surrounding schema; an `items` key with a boolean value is only
 * ever the array-items construct in OpenAPI, so a blunt key-based rewrite is
 * both correct and cheap.
 */
final class SchemaNormalizer
{
    /**
     * Recursively normalise boolean `items` in the decoded spec data.
     *
     * @param  mixed  $data  the raw decoded spec (associative arrays + scalars)
     * @return mixed the same structure with boolean `items` rewritten
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

            $result[$key] = self::normalize($value);
        }

        return $result;
    }
}
