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
        return (string) $value;
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
