<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Conformance;

/**
 * A single differential constraint case.
 *
 * Each entry pins exactly one OpenAPI constraint via a minimal schema, then
 * lists payloads the spec MUST accept and payloads the spec MUST reject. The
 * harness generates the Data class from the schema and runs every payload
 * through the generated rules() with the real Laravel validator. A valid
 * payload that is rejected, or an invalid payload that is accepted, is a
 * recorded mismatch (a candidate bug).
 *
 * The schema is always wrapped as one object component named by $construct, so
 * the generated class is "{$construct}Data". The property under test is the key
 * inside $properties.
 */
final class ConstraintCase
{
    /**
     * @param  string  $construct  unique PHP-safe name, becomes the component + class name
     * @param  string  $group  coarse family for the report (string, number, array, object, ...)
     * @param  array<string, mixed>  $schema  the object schema body (type/properties/required/...)
     * @param  list<array{label: string, payload: array<string, mixed>}>  $valid  payloads the spec accepts
     * @param  list<array{label: string, payload: array<string, mixed>, violates: string}>  $invalid  payloads the spec rejects
     */
    public function __construct(
        public readonly string $construct,
        public readonly string $group,
        public readonly array $schema,
        public readonly array $valid,
        public readonly array $invalid,
    ) {}
}

/**
 * The constraint catalog: one ConstraintCase per constraint/boundary the
 * generator claims to support, built mutation-style around boundary values.
 *
 * Intentionally adversarial. Several entries probe areas the package documents
 * as weak (object unions, additionalProperties: false) so the harness either
 * confirms the documented gap or finds it is worse than documented.
 */
final class ConstraintCatalog
{
    /**
     * @return list<ConstraintCase>
     */
    public static function cases(): array
    {
        return [
            ...self::stringCases(),
            ...self::numberCases(),
            ...self::arrayCases(),
            ...self::objectCases(),
            ...self::combinationCases(),
        ];
    }

    /**
     * Wrap a single property schema into an object component body, marking it
     * required so presence is enforced and the boundary is the only variable.
     *
     * @param  array<string, mixed>  $property
     * @return array<string, mixed>
     */
    private static function obj(string $prop, array $property, bool $required = true): array
    {
        $body = [
            'type' => 'object',
            'properties' => [$prop => $property],
        ];
        if ($required) {
            $body['required'] = [$prop];
        }

        return $body;
    }

    /**
     * @return list<ConstraintCase>
     */
    private static function stringCases(): array
    {
        return [
            new ConstraintCase(
                'StrMinLength', 'string',
                self::obj('v', ['type' => 'string', 'minLength' => 3]),
                [
                    ['label' => 'at boundary (3 chars)', 'payload' => ['v' => 'abc']],
                    ['label' => 'above boundary', 'payload' => ['v' => 'abcd']],
                ],
                [
                    ['label' => 'one char short (2 chars)', 'payload' => ['v' => 'ab'], 'violates' => 'minLength'],
                    ['label' => 'empty string', 'payload' => ['v' => ''], 'violates' => 'minLength'],
                ],
            ),
            new ConstraintCase(
                'StrMaxLength', 'string',
                self::obj('v', ['type' => 'string', 'maxLength' => 3]),
                [
                    ['label' => 'at boundary (3 chars)', 'payload' => ['v' => 'abc']],
                    ['label' => 'below boundary', 'payload' => ['v' => 'ab']],
                ],
                [
                    ['label' => 'one char over (4 chars)', 'payload' => ['v' => 'abcd'], 'violates' => 'maxLength'],
                ],
            ),
            new ConstraintCase(
                'StrPattern', 'string',
                self::obj('v', ['type' => 'string', 'pattern' => '^[a-z]+$']),
                [
                    ['label' => 'matching', 'payload' => ['v' => 'abc']],
                ],
                [
                    ['label' => 'non-matching (digits)', 'payload' => ['v' => 'abc1'], 'violates' => 'pattern'],
                    ['label' => 'non-matching (uppercase)', 'payload' => ['v' => 'ABC'], 'violates' => 'pattern'],
                ],
            ),
            new ConstraintCase(
                'StrFormatEmail', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'email']),
                [
                    ['label' => 'valid email', 'payload' => ['v' => 'a@b.com']],
                ],
                [
                    ['label' => 'free text', 'payload' => ['v' => 'not an email'], 'violates' => 'format:email'],
                    ['label' => 'missing domain', 'payload' => ['v' => 'a@'], 'violates' => 'format:email'],
                ],
            ),
            new ConstraintCase(
                'StrFormatUuid', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'uuid']),
                [
                    ['label' => 'valid uuid', 'payload' => ['v' => '550e8400-e29b-41d4-a716-446655440000']],
                ],
                [
                    ['label' => 'free text', 'payload' => ['v' => 'not-a-uuid'], 'violates' => 'format:uuid'],
                    ['label' => 'truncated uuid', 'payload' => ['v' => '550e8400-e29b-41d4-a716'], 'violates' => 'format:uuid'],
                ],
            ),
            new ConstraintCase(
                'StrFormatDate', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'date']),
                [
                    ['label' => 'Y-m-d date', 'payload' => ['v' => '2024-01-15']],
                ],
                [
                    ['label' => 'date-time on a date field', 'payload' => ['v' => '2024-01-15T10:30:00Z'], 'violates' => 'format:date'],
                    ['label' => 'free text', 'payload' => ['v' => 'yesterday'], 'violates' => 'format:date'],
                ],
            ),
            new ConstraintCase(
                'StrFormatDateTime', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'date-time']),
                [
                    ['label' => 'RFC3339 Z', 'payload' => ['v' => '2024-01-15T10:30:00Z']],
                    ['label' => 'RFC3339 offset', 'payload' => ['v' => '2024-01-15T10:30:00+02:00']],
                ],
                [
                    ['label' => 'bare date', 'payload' => ['v' => '2024-01-15'], 'violates' => 'format:date-time'],
                    ['label' => 'free text', 'payload' => ['v' => 'tomorrow'], 'violates' => 'format:date-time'],
                ],
            ),
            new ConstraintCase(
                'StrFormatUri', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'uri']),
                [
                    ['label' => 'http uri', 'payload' => ['v' => 'https://example.com/x']],
                ],
                [
                    ['label' => 'free text with spaces', 'payload' => ['v' => 'not a uri'], 'violates' => 'format:uri'],
                ],
            ),
            new ConstraintCase(
                'StrFormatIpv4', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'ipv4']),
                [
                    ['label' => 'valid ipv4', 'payload' => ['v' => '192.168.0.1']],
                ],
                [
                    ['label' => 'free text', 'payload' => ['v' => 'not-an-ip'], 'violates' => 'format:ipv4'],
                    ['label' => 'out-of-range octet', 'payload' => ['v' => '999.1.1.1'], 'violates' => 'format:ipv4'],
                ],
            ),
            new ConstraintCase(
                'StrFormatHostname', 'string',
                self::obj('v', ['type' => 'string', 'format' => 'hostname']),
                [
                    ['label' => 'valid hostname', 'payload' => ['v' => 'example.com']],
                    ['label' => 'multi-label hostname', 'payload' => ['v' => 'api.service.io']],
                    ['label' => 'single label', 'payload' => ['v' => 'localhost']],
                ],
                [
                    ['label' => 'spaces', 'payload' => ['v' => 'not a hostname'], 'violates' => 'format:hostname'],
                    ['label' => 'illegal characters', 'payload' => ['v' => 'bad_host!'], 'violates' => 'format:hostname'],
                    ['label' => 'leading-hyphen label', 'payload' => ['v' => '-bad.com'], 'violates' => 'format:hostname'],
                ],
            ),
            new ConstraintCase(
                'StrEnum', 'string',
                self::obj('v', ['type' => 'string', 'enum' => ['red', 'green', 'blue']]),
                [
                    ['label' => 'listed value', 'payload' => ['v' => 'green']],
                ],
                [
                    ['label' => 'unlisted value', 'payload' => ['v' => 'purple'], 'violates' => 'enum'],
                    ['label' => 'wrong case', 'payload' => ['v' => 'RED'], 'violates' => 'enum'],
                ],
            ),
            new ConstraintCase(
                'StrConst', 'string',
                self::obj('v', ['type' => 'string', 'const' => 'fixed']),
                [
                    ['label' => 'the const value', 'payload' => ['v' => 'fixed']],
                ],
                [
                    ['label' => 'any other value', 'payload' => ['v' => 'other'], 'violates' => 'const'],
                ],
            ),
            // #32: a length constraint serialised as a JSON string ("3") must
            // behave exactly like the numeric form. Before the SchemaNormalizer
            // coercion the rule was silently dropped and every value (even a too
            // short one) was accepted.
            new ConstraintCase(
                'StrMinLengthAsString', 'string',
                self::obj('v', ['type' => 'string', 'minLength' => '3']),
                [
                    ['label' => 'at boundary (3 chars)', 'payload' => ['v' => 'abc']],
                ],
                [
                    ['label' => 'one char short (2 chars)', 'payload' => ['v' => 'ab'], 'violates' => 'minLength(string-typed)'],
                ],
            ),
        ];
    }

    /**
     * @return list<ConstraintCase>
     */
    private static function numberCases(): array
    {
        return [
            new ConstraintCase(
                'NumMinimum', 'number',
                self::obj('v', ['type' => 'integer', 'minimum' => 5]),
                [
                    ['label' => 'at boundary', 'payload' => ['v' => 5]],
                    ['label' => 'just inside', 'payload' => ['v' => 6]],
                ],
                [
                    ['label' => 'just outside', 'payload' => ['v' => 4], 'violates' => 'minimum'],
                ],
            ),
            new ConstraintCase(
                'NumMaximum', 'number',
                self::obj('v', ['type' => 'integer', 'maximum' => 5]),
                [
                    ['label' => 'at boundary', 'payload' => ['v' => 5]],
                    ['label' => 'just inside', 'payload' => ['v' => 4]],
                ],
                [
                    ['label' => 'just outside', 'payload' => ['v' => 6], 'violates' => 'maximum'],
                ],
            ),
            new ConstraintCase(
                'NumExclusiveMin30', 'number',
                self::obj('v', ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true]),
                [
                    ['label' => 'just above boundary', 'payload' => ['v' => 6]],
                ],
                [
                    ['label' => 'equal value must be rejected', 'payload' => ['v' => 5], 'violates' => 'exclusiveMinimum'],
                    ['label' => 'below', 'payload' => ['v' => 4], 'violates' => 'exclusiveMinimum'],
                ],
            ),
            new ConstraintCase(
                'NumExclusiveMax30', 'number',
                self::obj('v', ['type' => 'integer', 'maximum' => 5, 'exclusiveMaximum' => true]),
                [
                    ['label' => 'just below boundary', 'payload' => ['v' => 4]],
                ],
                [
                    ['label' => 'equal value must be rejected', 'payload' => ['v' => 5], 'violates' => 'exclusiveMaximum'],
                    ['label' => 'above', 'payload' => ['v' => 6], 'violates' => 'exclusiveMaximum'],
                ],
            ),
            new ConstraintCase(
                'NumExclusiveMin31', 'number',
                self::obj('v', ['type' => 'integer', 'exclusiveMinimum' => 5]),
                [
                    ['label' => 'just above boundary', 'payload' => ['v' => 6]],
                ],
                [
                    ['label' => 'equal value must be rejected (3.1 numeric form)', 'payload' => ['v' => 5], 'violates' => 'exclusiveMinimum'],
                    ['label' => 'below', 'payload' => ['v' => 4], 'violates' => 'exclusiveMinimum'],
                ],
            ),
            new ConstraintCase(
                'NumExclusiveMax31', 'number',
                self::obj('v', ['type' => 'integer', 'exclusiveMaximum' => 5]),
                [
                    ['label' => 'just below boundary', 'payload' => ['v' => 4]],
                ],
                [
                    ['label' => 'equal value must be rejected (3.1 numeric form)', 'payload' => ['v' => 5], 'violates' => 'exclusiveMaximum'],
                    ['label' => 'above', 'payload' => ['v' => 6], 'violates' => 'exclusiveMaximum'],
                ],
            ),
            new ConstraintCase(
                'NumMultipleOf', 'number',
                self::obj('v', ['type' => 'integer', 'multipleOf' => 3]),
                [
                    ['label' => 'a multiple', 'payload' => ['v' => 9]],
                    ['label' => 'zero is a multiple', 'payload' => ['v' => 0]],
                ],
                [
                    ['label' => 'non-multiple', 'payload' => ['v' => 7], 'violates' => 'multipleOf'],
                ],
            ),
            new ConstraintCase(
                'NumMultipleOfFloat', 'number',
                self::obj('v', ['type' => 'number', 'multipleOf' => 0.5]),
                [
                    ['label' => 'a multiple of 0.5', 'payload' => ['v' => 1.5]],
                ],
                [
                    ['label' => 'non-multiple', 'payload' => ['v' => 1.3], 'violates' => 'multipleOf'],
                ],
            ),
            new ConstraintCase(
                'NumIntegerType', 'number',
                self::obj('v', ['type' => 'integer']),
                [
                    ['label' => 'integer', 'payload' => ['v' => 7]],
                ],
                [
                    ['label' => 'float must be rejected for integer', 'payload' => ['v' => 7.5], 'violates' => 'type:integer'],
                    ['label' => 'numeric string', 'payload' => ['v' => 'abc'], 'violates' => 'type:integer'],
                ],
            ),
            new ConstraintCase(
                'NumIntEnum', 'number',
                self::obj('v', ['type' => 'integer', 'enum' => [10, 20, 30]]),
                [
                    ['label' => 'listed value', 'payload' => ['v' => 20]],
                ],
                [
                    ['label' => 'unlisted value', 'payload' => ['v' => 25], 'violates' => 'enum'],
                ],
            ),
            new ConstraintCase(
                'NumFloatEnum', 'number',
                self::obj('v', ['type' => 'number', 'enum' => [1.5, 2.5]]),
                [
                    ['label' => 'listed value', 'payload' => ['v' => 2.5]],
                ],
                [
                    ['label' => 'unlisted value', 'payload' => ['v' => 3.5], 'violates' => 'enum'],
                ],
            ),
            new ConstraintCase(
                'NumConst', 'number',
                self::obj('v', ['type' => 'integer', 'const' => 42]),
                [
                    ['label' => 'the const value', 'payload' => ['v' => 42]],
                ],
                [
                    ['label' => 'any other value', 'payload' => ['v' => 41], 'violates' => 'const'],
                ],
            ),
            // #32: numeric bounds serialised as JSON strings ("0"/"120") must
            // behave like the numeric forms. Before the coercion both min: and
            // max: rules were silently dropped and any integer was accepted.
            new ConstraintCase(
                'NumMinMaxAsString', 'number',
                self::obj('v', ['type' => 'integer', 'minimum' => '0', 'maximum' => '120']),
                [
                    ['label' => 'at lower boundary', 'payload' => ['v' => 0]],
                    ['label' => 'at upper boundary', 'payload' => ['v' => 120]],
                    ['label' => 'inside range', 'payload' => ['v' => 30]],
                ],
                [
                    ['label' => 'below minimum', 'payload' => ['v' => -1], 'violates' => 'minimum(string-typed)'],
                    ['label' => 'above maximum', 'payload' => ['v' => 121], 'violates' => 'maximum(string-typed)'],
                ],
            ),
        ];
    }

    /**
     * @return list<ConstraintCase>
     */
    private static function arrayCases(): array
    {
        return [
            new ConstraintCase(
                'ArrMinItems', 'array',
                self::obj('v', ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string']]),
                [
                    ['label' => 'at boundary', 'payload' => ['v' => ['a', 'b']]],
                ],
                [
                    ['label' => 'one short', 'payload' => ['v' => ['a']], 'violates' => 'minItems'],
                    ['label' => 'empty', 'payload' => ['v' => []], 'violates' => 'minItems'],
                ],
            ),
            new ConstraintCase(
                'ArrMaxItems', 'array',
                self::obj('v', ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string']]),
                [
                    ['label' => 'at boundary', 'payload' => ['v' => ['a', 'b']]],
                ],
                [
                    ['label' => 'one over', 'payload' => ['v' => ['a', 'b', 'c']], 'violates' => 'maxItems'],
                ],
            ),
            new ConstraintCase(
                'ArrUniqueItems', 'array',
                self::obj('v', ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string']]),
                [
                    ['label' => 'distinct items', 'payload' => ['v' => ['a', 'b']]],
                ],
                [
                    ['label' => 'duplicate items', 'payload' => ['v' => ['a', 'a']], 'violates' => 'uniqueItems'],
                ],
            ),
            new ConstraintCase(
                'ArrItemMinLength', 'array',
                self::obj('v', ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 3]]),
                [
                    ['label' => 'all items long enough', 'payload' => ['v' => ['abc', 'abcd']]],
                ],
                [
                    ['label' => 'one item too short', 'payload' => ['v' => ['abc', 'ab']], 'violates' => 'items.minLength'],
                ],
            ),
            new ConstraintCase(
                'ArrItemEnum', 'array',
                self::obj('v', ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x', 'y']]]),
                [
                    ['label' => 'all items listed', 'payload' => ['v' => ['x', 'y']]],
                ],
                [
                    ['label' => 'one item unlisted', 'payload' => ['v' => ['x', 'z']], 'violates' => 'items.enum'],
                ],
            ),
            new ConstraintCase(
                'ArrItemMinimum', 'array',
                self::obj('v', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 5]]),
                [
                    ['label' => 'all items in range', 'payload' => ['v' => [5, 6]]],
                ],
                [
                    ['label' => 'one item below minimum', 'payload' => ['v' => [5, 4]], 'violates' => 'items.minimum'],
                ],
            ),
            new ConstraintCase(
                'ArrNested', 'array',
                self::obj('v', ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]]]),
                [
                    ['label' => 'nested arrays valid', 'payload' => ['v' => [[1, 2], [3]]]],
                ],
                [
                    ['label' => 'inner item below minimum', 'payload' => ['v' => [[1], [-1]]], 'violates' => 'items.items.minimum'],
                ],
            ),
        ];
    }

    /**
     * @return list<ConstraintCase>
     */
    private static function objectCases(): array
    {
        return [
            new ConstraintCase(
                'ObjRequired', 'object',
                self::obj('v', ['type' => 'string']),
                [
                    ['label' => 'required present', 'payload' => ['v' => 'x']],
                ],
                [
                    ['label' => 'required missing', 'payload' => [], 'violates' => 'required'],
                ],
            ),
            new ConstraintCase(
                'ObjOptionalAbsent', 'object',
                [
                    'type' => 'object',
                    'required' => ['must'],
                    'properties' => [
                        'must' => ['type' => 'string'],
                        'maybe' => ['type' => 'string', 'minLength' => 3],
                    ],
                ],
                [
                    ['label' => 'optional absent is allowed', 'payload' => ['must' => 'x']],
                    ['label' => 'optional present and valid', 'payload' => ['must' => 'x', 'maybe' => 'abc']],
                ],
                [
                    ['label' => 'optional present but invalid', 'payload' => ['must' => 'x', 'maybe' => 'ab'], 'violates' => 'optional.minLength'],
                ],
            ),
            new ConstraintCase(
                'ObjDefaultOptional', 'object',
                [
                    'type' => 'object',
                    'properties' => [
                        'flag' => ['type' => 'string', 'default' => 'on', 'minLength' => 2],
                    ],
                ],
                [
                    ['label' => 'property with default is optional on input', 'payload' => []],
                    ['label' => 'default-backed property present and valid', 'payload' => ['flag' => 'yes']],
                ],
                [
                    ['label' => 'default-backed property present but violates constraint', 'payload' => ['flag' => 'x'], 'violates' => 'default.minLength'],
                ],
            ),
            new ConstraintCase(
                'ObjNullableTrue', 'object',
                [
                    'type' => 'object',
                    'required' => ['n'],
                    'properties' => [
                        'n' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                [
                    ['label' => 'null accepted on nullable field', 'payload' => ['n' => null]],
                    ['label' => 'string accepted on nullable field', 'payload' => ['n' => 'x']],
                ],
                [
                    ['label' => 'wrong type still rejected', 'payload' => ['n' => 123], 'violates' => 'type:string'],
                ],
            ),
            // #33: `nullable` serialised as the string "true" must make the
            // field nullable. Before the coercion the string was not === true,
            // so null was rejected on a field the spec marks nullable.
            new ConstraintCase(
                'ObjNullableTrueAsString', 'object',
                [
                    'type' => 'object',
                    'required' => ['n'],
                    'properties' => [
                        'n' => ['type' => 'string', 'nullable' => 'true'],
                    ],
                ],
                [
                    ['label' => 'null accepted on string-typed nullable field', 'payload' => ['n' => null]],
                    ['label' => 'string accepted on string-typed nullable field', 'payload' => ['n' => 'x']],
                ],
                [
                    ['label' => 'wrong type still rejected', 'payload' => ['n' => 123], 'violates' => 'type:string'],
                ],
            ),
            new ConstraintCase(
                'ObjNonNullable', 'object',
                [
                    'type' => 'object',
                    'required' => ['n'],
                    'properties' => [
                        'n' => ['type' => 'string'],
                    ],
                ],
                [
                    ['label' => 'string accepted', 'payload' => ['n' => 'x']],
                ],
                [
                    ['label' => 'null on a non-nullable typed field must be rejected', 'payload' => ['n' => null], 'violates' => 'non-nullable'],
                ],
            ),
            // A typed additionalProperties map hosted under a named property so
            // the generator emits a class (top-level pure-map components inline
            // per issue #9 and emit no class of their own).
            new ConstraintCase(
                'ObjAddlPropsTyped', 'object',
                [
                    'type' => 'object',
                    'required' => ['scores'],
                    'properties' => [
                        'scores' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                ],
                [
                    ['label' => 'map values valid', 'payload' => ['scores' => ['a' => 1, 'b' => 2]]],
                ],
                [
                    ['label' => 'a map value of wrong type', 'payload' => ['scores' => ['a' => 'notint']], 'violates' => 'addlProps.type'],
                    ['label' => 'a map value below minimum', 'payload' => ['scores' => ['a' => -1]], 'violates' => 'addlProps.minimum'],
                ],
            ),
            new ConstraintCase(
                'ObjAddlPropsString', 'object',
                [
                    'type' => 'object',
                    'required' => ['labels'],
                    'properties' => [
                        'labels' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string', 'minLength' => 2],
                        ],
                    ],
                ],
                [
                    ['label' => 'map values valid', 'payload' => ['labels' => ['a' => 'xx']]],
                ],
                [
                    ['label' => 'a map value too short', 'payload' => ['labels' => ['a' => 'x']], 'violates' => 'addlProps.minLength'],
                ],
            ),
            // KNOWN-WEAK probe: additionalProperties: false. Package documents this
            // as NOT enforced, so an extra key being accepted is a confirmation of
            // the documented gap, not a newly found bug.
            new ConstraintCase(
                'ObjAddlPropsFalse', 'object',
                [
                    'type' => 'object',
                    'required' => ['known'],
                    'additionalProperties' => false,
                    'properties' => [
                        'known' => ['type' => 'string'],
                    ],
                ],
                [
                    ['label' => 'only known property', 'payload' => ['known' => 'x']],
                ],
                [
                    ['label' => 'extra undeclared property must be rejected', 'payload' => ['known' => 'x', 'extra' => 'y'], 'violates' => 'additionalProperties:false'],
                ],
            ),
        ];
    }

    /**
     * @return list<ConstraintCase>
     */
    private static function combinationCases(): array
    {
        return [
            new ConstraintCase(
                'ComboRequiredFormat', 'combination',
                [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ],
                ],
                [
                    ['label' => 'present and valid', 'payload' => ['email' => 'a@b.com']],
                ],
                [
                    ['label' => 'present but invalid format', 'payload' => ['email' => 'nope'], 'violates' => 'format:email'],
                    ['label' => 'missing required', 'payload' => [], 'violates' => 'required'],
                ],
            ),
            new ConstraintCase(
                'ComboNullableConstraint', 'combination',
                [
                    'type' => 'object',
                    'required' => ['v'],
                    'properties' => [
                        'v' => ['type' => 'string', 'nullable' => true, 'minLength' => 3],
                    ],
                ],
                [
                    ['label' => 'null bypasses the length constraint', 'payload' => ['v' => null]],
                    ['label' => 'non-null value satisfies length', 'payload' => ['v' => 'abc']],
                ],
                [
                    ['label' => 'non-null value too short', 'payload' => ['v' => 'ab'], 'violates' => 'minLength'],
                ],
            ),
            new ConstraintCase(
                'ComboDefaultConstraint', 'combination',
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => ['type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 10],
                    ],
                ],
                [
                    ['label' => 'absent (uses default)', 'payload' => []],
                    ['label' => 'present and in range', 'payload' => ['n' => 7]],
                ],
                [
                    ['label' => 'present but over maximum', 'payload' => ['n' => 11], 'violates' => 'maximum'],
                ],
            ),
            // allOf-merged required across members: name required by member A,
            // age required by member B. The flat class must require BOTH.
            new ConstraintCase(
                'ComboAllOfRequired', 'combination',
                [
                    'allOf' => [
                        [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => ['name' => ['type' => 'string']],
                        ],
                        [
                            'type' => 'object',
                            'required' => ['age'],
                            'properties' => ['age' => ['type' => 'integer', 'minimum' => 0]],
                        ],
                    ],
                ],
                [
                    ['label' => 'both required present', 'payload' => ['name' => 'x', 'age' => 1]],
                ],
                [
                    ['label' => 'missing member-B required', 'payload' => ['name' => 'x'], 'violates' => 'allOf.required'],
                    ['label' => 'missing member-A required', 'payload' => ['age' => 1], 'violates' => 'allOf.required'],
                    ['label' => 'merged constraint violated', 'payload' => ['name' => 'x', 'age' => -1], 'violates' => 'allOf.minimum'],
                ],
            ),
        ];
    }

    /**
     * Object-union (oneOf of $ref objects) cases need their own component
     * schemas alongside the host, so they are described separately and the
     * harness assembles a multi-schema document for them.
     *
     * @return list<array{construct: string, schemas: array<string, mixed>, root: string, valid: list<array{label: string, payload: array<string, mixed>}>, invalid: list<array{label: string, payload: array<string, mixed>, violates: string}>}>
     */
    public static function unionCases(): array
    {
        return [
            // oneOf of two $ref objects. Cat requires `meow`, Dog requires `bark`.
            // A payload matching neither variant MUST be rejected by a correct
            // validator. This probes the documented object-union weakness.
            [
                'construct' => 'PetHolder',
                'root' => 'PetHolder',
                'schemas' => [
                    'Cat' => [
                        'type' => 'object',
                        'required' => ['meow'],
                        'properties' => ['meow' => ['type' => 'string']],
                    ],
                    'Dog' => [
                        'type' => 'object',
                        'required' => ['bark'],
                        'properties' => ['bark' => ['type' => 'string']],
                    ],
                    'PetHolder' => [
                        'type' => 'object',
                        'required' => ['pet'],
                        'properties' => [
                            'pet' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/Cat'],
                                    ['$ref' => '#/components/schemas/Dog'],
                                ],
                            ],
                        ],
                    ],
                ],
                'valid' => [
                    ['label' => 'matches Cat variant', 'payload' => ['pet' => ['meow' => 'mrr']]],
                    ['label' => 'matches Dog variant', 'payload' => ['pet' => ['bark' => 'woof']]],
                ],
                'invalid' => [
                    ['label' => 'matches neither variant must be rejected', 'payload' => ['pet' => ['quack' => 'q']], 'violates' => 'oneOf.no-match'],
                ],
            ],
            // DISCRIMINATED oneOf (issue #38): the same Pet union, now WITH a
            // discriminator. Unlike the undiscriminated PetHolder above (which is
            // presence-only and a tracked known gap), this is FULLY enforced: the
            // generator emits an abstract morphable base, so the right variant is
            // selected and its own rules are applied. The oracle proves the
            // accept/reject behaviour with no known-gap entry, demonstrating real
            // variant validation, not just type-checking.
            [
                'construct' => 'DiscriminatedPetHolder',
                'root' => 'DiscriminatedPetHolder',
                'schemas' => [
                    'DiscPet' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/DiscCat'],
                            ['$ref' => '#/components/schemas/DiscDog'],
                        ],
                        'discriminator' => [
                            'propertyName' => 'petType',
                            'mapping' => ['cat' => 'DiscCat', 'dog' => 'DiscDog'],
                        ],
                    ],
                    'DiscCat' => [
                        'type' => 'object',
                        'required' => ['petType', 'meow'],
                        'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']],
                    ],
                    'DiscDog' => [
                        'type' => 'object',
                        'required' => ['petType', 'bark'],
                        'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']],
                    ],
                    'DiscriminatedPetHolder' => [
                        'type' => 'object',
                        'required' => ['pet'],
                        'properties' => ['pet' => ['$ref' => '#/components/schemas/DiscPet']],
                    ],
                ],
                'valid' => [
                    ['label' => 'cat discriminator with a valid cat shape', 'payload' => ['pet' => ['petType' => 'cat', 'meow' => 'mrr']]],
                    ['label' => 'dog discriminator with a valid dog shape', 'payload' => ['pet' => ['petType' => 'dog', 'bark' => 'woof']]],
                ],
                'invalid' => [
                    ['label' => 'cat discriminator missing the cat-required meow', 'payload' => ['pet' => ['petType' => 'cat', 'bark' => 'woof']], 'violates' => 'discriminator.variant.required'],
                    ['label' => 'unmapped discriminator value', 'payload' => ['pet' => ['petType' => 'fish']], 'violates' => 'discriminator.unmapped'],
                    ['label' => 'missing the discriminator property', 'payload' => ['pet' => ['meow' => 'mrr']], 'violates' => 'discriminator.missing'],
                ],
            ],
        ];
    }
}
