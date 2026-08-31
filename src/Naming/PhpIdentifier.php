<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Naming;

use Illuminate\Support\Str;

/**
 * Converts raw OpenAPI names (schema names, property keys, $refs) into valid,
 * idiomatic PHP identifiers. Ported from the openapi-zod-ts naming utilities,
 * adapted for PHP rules: StudlyCaps class names that avoid reserved words,
 * camelCase property names, and #[MapName] detection for wire-name drift.
 *
 * All splitting uses linear-time character-class patterns (no nested quantifiers)
 * to stay free of polynomial ReDoS on adversarial spec names.
 *
 * @internal
 */
final class PhpIdentifier
{
    /**
     * PHP keywords and soft-reserved type names that cannot be used as a class,
     * interface, trait, or enum name. Lower-cased; matching is case-insensitive.
     *
     * @var array<string, true>
     */
    private const RESERVED = [
        'abstract' => true, 'and' => true, 'array' => true, 'as' => true,
        'bool' => true, 'break' => true, 'callable' => true, 'case' => true,
        'catch' => true, 'class' => true, 'clone' => true, 'const' => true,
        'continue' => true, 'declare' => true, 'default' => true, 'do' => true,
        'echo' => true, 'else' => true, 'elseif' => true, 'empty' => true,
        'enddeclare' => true, 'endfor' => true, 'endforeach' => true, 'endif' => true,
        'endswitch' => true, 'endwhile' => true, 'enum' => true, 'eval' => true,
        'exit' => true, 'extends' => true, 'false' => true, 'final' => true,
        'finally' => true, 'float' => true, 'fn' => true, 'for' => true,
        'foreach' => true, 'function' => true, 'global' => true, 'goto' => true,
        'if' => true, 'implements' => true, 'include' => true, 'include_once' => true,
        'instanceof' => true, 'insteadof' => true, 'int' => true, 'interface' => true,
        'isset' => true, 'iterable' => true, 'list' => true, 'match' => true,
        'mixed' => true, 'namespace' => true, 'never' => true, 'new' => true,
        'null' => true, 'object' => true, 'or' => true, 'parent' => true,
        'print' => true, 'private' => true, 'protected' => true, 'public' => true,
        'readonly' => true, 'require' => true, 'require_once' => true, 'return' => true,
        'self' => true, 'static' => true, 'string' => true, 'switch' => true,
        'throw' => true, 'trait' => true, 'true' => true, 'try' => true,
        'unset' => true, 'use' => true, 'var' => true, 'void' => true,
        'while' => true, 'xor' => true, 'yield' => true,
    ];

    /**
     * Convert a raw name into a StudlyCaps PHP class identifier.
     * Reserved words are prefixed with an underscore; an empty result yields '_'.
     */
    public static function toClassName(string $name): string
    {
        $parts = self::words($name);

        if ($parts === []) {
            return '_';
        }

        $joined = '';
        foreach ($parts as $part) {
            $joined .= ucfirst($part);
        }

        if (preg_match('/^[^a-zA-Z_]/', $joined) === 1) {
            $joined = '_'.$joined;
        }

        if (self::isReserved($joined)) {
            return '_'.$joined;
        }

        return $joined;
    }

    /**
     * Convert a raw property/parameter key into a camelCase PHP identifier.
     * PHP variable names may be reserved words, so only the leading-digit case
     * needs escaping.
     */
    public static function toPropertyName(string $name): string
    {
        $parts = self::words($name);

        if ($parts === []) {
            return 'value';
        }

        $first = array_shift($parts);
        $camel = lcfirst($first);
        foreach ($parts as $part) {
            $camel .= ucfirst($part);
        }

        if (preg_match('/^[0-9]/', $camel) === 1) {
            return '_'.$camel;
        }

        // `$this` is the one identifier PHP forbids as a parameter variable
        // ("Cannot use $this as parameter"). Every other reserved word (for,
        // class, list, match, ...) is legal as a parameter name, so this is the
        // single special case. Map it to `_this`; the emitter's wire-name drift
        // check then adds #[MapName('this')] so (de)serialization round-trips.
        if (strtolower($camel) === 'this') {
            return '_'.$camel;
        }

        return $camel;
    }

    /**
     * Resolve a JSON Schema $ref (e.g. '#/components/schemas/Foo') to a class
     * identifier, honouring a rename map produced by the dedup pass.
     *
     * @param  array<string, string>  $renameMap
     */
    public static function refToClassName(string $ref, array $renameMap = []): string
    {
        $parts = explode('/', $ref);
        $raw = end($parts);

        if ($raw === '') {
            return '_';
        }

        if (isset($renameMap[$raw])) {
            return $renameMap[$raw];
        }

        return self::toClassName($raw);
    }

    /**
     * Whether a generated property name differs from its wire name and therefore
     * needs a #[MapName] attribute to round-trip correctly.
     */
    public static function needsMapName(string $wireName, string $propertyName): bool
    {
        return $wireName !== $propertyName;
    }

    public static function isReserved(string $name): bool
    {
        return isset(self::RESERVED[strtolower($name)]);
    }

    /**
     * Split a raw name into alphanumeric words. Non-ASCII letters are folded to their
     * closest ASCII equivalent first ("Straße" -> "Strasse", "café" -> "cafe"), so a
     * language's own diacritics do not just get deleted as separators; apostrophes are
     * stripped without splitting ("user's" -> "users"); every other non-alphanumeric
     * run (including a script with no ASCII equivalent) is a boundary.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $ascii = Str::ascii($name);
        $withoutApostrophes = str_replace("'", '', $ascii);
        $parts = preg_split('/[^a-zA-Z0-9]+/', $withoutApostrophes, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
