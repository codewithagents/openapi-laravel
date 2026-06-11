<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;

/**
 * #20: boolean `items` (valid OpenAPI 3.1) is rewritten before cebe sees it,
 * since cebe cannot instantiate a Schema from a boolean. `items: true` becomes
 * an empty schema (any); `items: false` is dropped (a closed tuple's "no extra
 * items" has no Laravel representation). Boolean `additionalProperties` is NOT
 * touched, because cebe accepts it.
 */
it('rewrites items: true to an empty schema', function () {
    $out = SchemaNormalizer::normalize(['type' => 'array', 'items' => true]);

    expect($out)->toBe(['type' => 'array', 'items' => []]);
});

it('drops items: false (closed tuple)', function () {
    $out = SchemaNormalizer::normalize([
        'type' => 'array',
        'prefixItems' => [['type' => 'string']],
        'items' => false,
    ]);

    expect($out)->toBe([
        'type' => 'array',
        'prefixItems' => [['type' => 'string']],
    ]);
});

it('leaves an object-schema items untouched', function () {
    $input = ['type' => 'array', 'items' => ['type' => 'string']];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('does not touch boolean additionalProperties (cebe accepts those)', function () {
    $input = ['type' => 'object', 'additionalProperties' => false];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('normalises boolean items at any nesting depth', function () {
    $out = SchemaNormalizer::normalize([
        'components' => [
            'schemas' => [
                'Outer' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => true,
                    ],
                ],
            ],
        ],
    ]);

    expect($out['components']['schemas']['Outer']['items']['items'])->toBe([]);
});

it('passes through scalars and null unchanged', function () {
    expect(SchemaNormalizer::normalize('x'))->toBe('x')
        ->and(SchemaNormalizer::normalize(null))->toBeNull()
        ->and(SchemaNormalizer::normalize(42))->toBe(42);
});

/*
 * #32: a numeric keyword serialised as a strictly-numeric JSON string is
 * coerced to a proper number so the emitter's is_int/is_float checks fire.
 * A non-numeric string is left untouched and stays ignored downstream.
 */
it('coerces a strictly-numeric string minimum to an int', function () {
    $out = SchemaNormalizer::normalize(['type' => 'integer', 'minimum' => '8']);

    expect($out)->toBe(['type' => 'integer', 'minimum' => 8]);
});

it('coerces minLength/maxLength/minItems/maxItems strings to ints', function () {
    $out = SchemaNormalizer::normalize([
        'minLength' => '3',
        'maxLength' => '10',
        'minItems' => '1',
        'maxItems' => '5',
    ]);

    expect($out)->toBe([
        'minLength' => 3,
        'maxLength' => 10,
        'minItems' => 1,
        'maxItems' => 5,
    ]);
});

it('coerces a fractional multipleOf string to a float', function () {
    $out = SchemaNormalizer::normalize(['multipleOf' => '0.5']);

    expect($out['multipleOf'])->toBe(0.5);
});

it('coerces a numeric exclusiveMinimum/Maximum string (3.1 form) to a number', function () {
    $out = SchemaNormalizer::normalize(['exclusiveMinimum' => '5', 'exclusiveMaximum' => '9']);

    expect($out)->toBe(['exclusiveMinimum' => 5, 'exclusiveMaximum' => 9]);
});

it('leaves a non-numeric constraint string untouched', function () {
    $input = ['type' => 'string', 'minLength' => '8abc'];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('leaves an already-numeric constraint untouched', function () {
    $input = ['minimum' => 8, 'multipleOf' => 0.5];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('does not touch a boolean exclusiveMinimum (3.0 companion form)', function () {
    $input = ['minimum' => 5, 'exclusiveMinimum' => true];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

/*
 * #33: `nullable` serialised as the string "true"/"false" is coerced to the
 * matching boolean (case-insensitive). Any other string is left untouched.
 */
it('coerces nullable string "true" to boolean true', function () {
    $out = SchemaNormalizer::normalize(['type' => 'string', 'nullable' => 'true']);

    expect($out)->toBe(['type' => 'string', 'nullable' => true]);
});

it('coerces nullable string "false" to boolean false', function () {
    $out = SchemaNormalizer::normalize(['type' => 'string', 'nullable' => 'false']);

    expect($out)->toBe(['type' => 'string', 'nullable' => false]);
});

it('coerces nullable case-insensitively', function () {
    $out = SchemaNormalizer::normalize(['nullable' => 'TRUE']);

    expect($out)->toBe(['nullable' => true]);
});

it('leaves a boolean nullable untouched', function () {
    $input = ['nullable' => true];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('leaves an unrecognised nullable string untouched (treated as not-nullable)', function () {
    $input = ['nullable' => 'yes'];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('coerces string-typed constraints at any nesting depth', function () {
    $out = SchemaNormalizer::normalize([
        'components' => [
            'schemas' => [
                'User' => [
                    'type' => 'object',
                    'properties' => [
                        'age' => ['type' => 'integer', 'minimum' => '0', 'maximum' => '120'],
                        'bio' => ['type' => 'string', 'nullable' => 'true'],
                    ],
                ],
            ],
        ],
    ]);

    $props = $out['components']['schemas']['User']['properties'];
    expect($props['age'])->toBe(['type' => 'integer', 'minimum' => 0, 'maximum' => 120])
        ->and($props['bio'])->toBe(['type' => 'string', 'nullable' => true]);
});
