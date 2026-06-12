<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;

/**
 * #20: boolean `items` (valid OpenAPI 3.1) is rewritten before cebe sees it,
 * since cebe cannot instantiate a Schema from a boolean. `items: true` becomes
 * an empty schema (any); `items: false` is dropped, but next to a non-empty
 * `prefixItems` list the closed tuple's length survives as a synthesized
 * `maxItems` (#82) so the emitter can enforce it as a count rule. Boolean
 * `additionalProperties` is NOT touched, because cebe accepts it.
 */
it('rewrites items: true to an empty schema', function () {
    $out = SchemaNormalizer::normalize(['type' => 'array', 'items' => true]);

    expect($out)->toBe(['type' => 'array', 'items' => []]);
});

it('drops items: false and synthesizes maxItems from the tuple size (closed tuple, #82)', function () {
    $out = SchemaNormalizer::normalize([
        'type' => 'array',
        'prefixItems' => [['type' => 'string']],
        'items' => false,
    ]);

    expect($out)->toBe([
        'type' => 'array',
        'prefixItems' => [['type' => 'string']],
        'maxItems' => 1,
    ]);
});

it('drops items: false without prefixItems and synthesizes nothing', function () {
    $out = SchemaNormalizer::normalize(['type' => 'array', 'items' => false]);

    expect($out)->toBe(['type' => 'array']);
});

it('tightens a larger explicit maxItems to the closed tuple size', function () {
    $out = SchemaNormalizer::normalize([
        'type' => 'array',
        'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
        'maxItems' => 5,
        'items' => false,
    ]);

    expect($out['maxItems'])->toBe(2);
});

it('keeps a tighter explicit maxItems under a closed tuple, coercing a numeric string (#32)', function () {
    $out = SchemaNormalizer::normalize([
        'type' => 'array',
        'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
        'maxItems' => '1',
        'items' => false,
    ]);

    expect($out['maxItems'])->toBe(1);
});

it('replaces a malformed maxItems with the closed tuple size', function () {
    $out = SchemaNormalizer::normalize([
        'type' => 'array',
        'prefixItems' => [['type' => 'string']],
        'maxItems' => 'lots',
        'items' => false,
    ]);

    expect($out['maxItems'])->toBe(1);
});

it('does not synthesize maxItems for an open tuple (no items: false)', function () {
    $input = [
        'type' => 'array',
        'prefixItems' => [['type' => 'string']],
    ];

    expect(SchemaNormalizer::normalize($input))->toBe($input);
});

it('does not synthesize maxItems when prefixItems is not a list', function () {
    $out = SchemaNormalizer::normalize([
        'type' => 'array',
        'prefixItems' => ['first' => ['type' => 'string']],
        'items' => false,
    ]);

    expect($out)->toBe([
        'type' => 'array',
        'prefixItems' => ['first' => ['type' => 'string']],
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

it('keeps an out-of-int-range numeric string as a float without warning', function () {
    // An int64 bound past PHP_INT_MAX (real-world specs carry these). Casting an
    // out-of-range float to int warns and truncates, so it must stay a float.
    $out = SchemaNormalizer::normalize(['maximum' => '9223372036854775807999']);

    expect($out['maximum'])->toBeFloat();
});

it('coerces the largest in-range int64 bound to an exact int', function () {
    // PHP_INT_MAX is integer-shaped and in range, so it stays an exact int. The
    // no-warning guarantee for out-of-range magnitudes is covered by the corpus
    // parse gate (a real int64 bound previously triggered a float-to-int warning).
    $out = SchemaNormalizer::normalize(['maximum' => (string) PHP_INT_MAX]);

    expect($out['maximum'])->toBe(PHP_INT_MAX);
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
