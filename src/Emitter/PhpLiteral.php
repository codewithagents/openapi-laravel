<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Rendering helpers for spec-derived values embedded in generated PHP source.
 *
 * The OpenAPI spec is untrusted input, so every value that lands inside a
 * single-quoted string or a docblock goes through these escapes. Extracted
 * from ModelGenerator (issue #109) so the emitter collaborators share one
 * implementation.
 *
 * @internal
 */
final class PhpLiteral
{
    public static function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    public static function scalarLiteral(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return self::numberLiteral($value);
        }

        return "'".self::escapeSingleQuoted($value)."'";
    }

    public static function numberLiteral(int|float $value): string
    {
        $rendered = (string) $value;

        // A small- or large-magnitude float stringifies in scientific notation
        // (`(string) 1e-7` is `"1.0E-7"`). That form breaks a Laravel rule-string
        // parameter (`min:1.0E-7`, `multipleOf:1.0E-7`), where the validator
        // reads the literal text and the `E` is not understood as an exponent,
        // and it makes a generated PHP default needlessly opaque. The exponent is
        // expanded into plain fixed-decimal notation, preserving the exact digits
        // the cast produced (issue #148). A non-scientific value is returned
        // untouched, so every normal-range number is byte-identical to before.
        if (stripos($rendered, 'e') === false) {
            return $rendered;
        }

        return self::expandScientific($rendered);
    }

    /**
     * Expand a scientific-notation decimal string (`"1.0E-7"`, `"-2.5E+20"`)
     * into plain fixed-decimal notation (`"0.0000001"`, `"-250000000000000000000"`)
     * by shifting the decimal point per the exponent. The mantissa digits are
     * preserved verbatim; only the radix point moves, so the rendered value is
     * exactly the one the `(string)` cast produced, just without the exponent.
     */
    private static function expandScientific(string $rendered): string
    {
        $sign = '';
        if ($rendered !== '' && ($rendered[0] === '-' || $rendered[0] === '+')) {
            $sign = $rendered[0] === '-' ? '-' : '';
            $rendered = substr($rendered, 1);
        }

        [$mantissa, $exponentPart] = preg_split('/[eE]/', $rendered, 2) ?: [$rendered, '0'];
        $exponent = (int) $exponentPart;

        $dot = strpos($mantissa, '.');
        if ($dot === false) {
            $intDigits = $mantissa;
            $fracDigits = '';
        } else {
            $intDigits = substr($mantissa, 0, $dot);
            $fracDigits = substr($mantissa, $dot + 1);
        }

        $digits = $intDigits.$fracDigits;
        // Where the radix point lands, measured from the left of $digits, after
        // applying the exponent shift.
        $pointPosition = strlen($intDigits) + $exponent;

        if ($pointPosition <= 0) {
            $result = '0.'.str_repeat('0', -$pointPosition).$digits;
        } elseif ($pointPosition >= strlen($digits)) {
            $result = $digits.str_repeat('0', $pointPosition - strlen($digits));
        } else {
            $result = substr($digits, 0, $pointPosition).'.'.substr($digits, $pointPosition);
        }

        if (str_contains($result, '.')) {
            $result = rtrim(rtrim($result, '0'), '.');
        }

        return $result === '' ? '0' : $sign.$result;
    }

    /**
     * A `['a', 'b']` PHP array literal of strings for the closed-object rule's
     * allow-lists (declared wire names, delimited patternProperties patterns).
     * Each item is single-quote-escaped (both come from untrusted spec input),
     * mirroring how MapName renders a wire name.
     *
     * @param  list<string>  $values
     */
    public static function stringListLiteral(array $values): string
    {
        $items = array_map(
            static fn (string $value): string => "'".self::escapeSingleQuoted($value)."'",
            array_values(array_unique($values)),
        );

        return '['.implode(', ', $items).']';
    }

    /**
     * Neutralize spec-derived free text before it is placed inside a `/** ... *\/`
     * docblock. Two hazards: a literal `*\/` would close the comment early and let
     * the rest of the value inject raw PHP, and newlines or other control
     * characters would let a value forge extra doc lines or break out. Every `*\/`
     * becomes `* /` and all control characters (newlines, tabs, etc.) collapse to
     * a single space. Mirrors the server scaffold's docblockSafe(): the OpenAPI
     * spec is untrusted input. (The two live in separate emitter layers that
     * Deptrac keeps apart, so the small duplication is intentional.)
     */
    public static function docblockSafe(string $value): string
    {
        $value = str_replace('*/', '* /', $value);
        $value = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value);

        return trim($value);
    }
}
