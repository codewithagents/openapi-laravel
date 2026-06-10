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
