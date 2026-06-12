<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Tuple `prefixItems` per-index rules (issue #82). Laravel addresses tuple
 * positions directly (`field.0`, `field.1`), so each prefixItems position gets
 * the rules its schema pins, reusing the shared inline-value constraint
 * mapping. The closed-tuple form (`items: false`) survives the reader's
 * normalization rewrite (#20) as a synthesized `maxItems` and lands in the
 * count rules. Typing keeps degrading gracefully (`array<int, mixed>`); this
 * issue is rules-only.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generatePrefixItemsSchemas(array $schemas): array
{
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $decoded = json_decode((string) json_encode($document), true);

    $spec = (new OpenApiReader)->read($decoded);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return (new ModelGenerator)->generate($spec);
}

it('emits per-index rules for prefixItems positions', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => 'integer', 'minimum' => 1],
                        ['type' => 'string', 'minLength' => 2],
                        ['type' => 'boolean'],
                    ],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'v' => ['sometimes', 'array'],")
        ->and($code)->toContain("'v.0' => ['integer', 'min:1'],")
        ->and($code)->toContain("'v.1' => ['string', 'min:2'],")
        ->and($code)->toContain("'v.2' => ['boolean'],");
});

it('synthesizes max:N for the closed tuple form (items: false)', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => 'string'],
                        ['type' => 'integer'],
                    ],
                    'items' => false,
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'v' => ['sometimes', 'array', 'max:2'],")
        ->and($code)->toContain("'v.0' => ['string'],")
        ->and($code)->toContain("'v.1' => ['integer'],");
});

it('keeps a tighter explicit maxItems over the synthesized closed-tuple bound', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [['type' => 'string'], ['type' => 'string']],
                    'items' => false,
                    'maxItems' => 1,
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain("'v' => ['sometimes', 'array', 'max:1'],");
});

it('emits min/max for a tuple whose length minItems/maxItems pin', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
                    'items' => false,
                    'minItems' => 2,
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain("'v' => ['sometimes', 'array', 'max:2', 'min:2'],");
});

it('emits enum and format rules for tuple positions through the shared mapping', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => 'string', 'enum' => ['x', 'y']],
                        ['type' => 'string', 'format' => 'email'],
                    ],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'v.0' => [Rule::in(['x', 'y'])],")
        ->and($code)->toContain("'v.1' => ['string', 'email'],");
});

it('prefixes nullable for a nullable tuple position', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => ['string', 'null']],
                        ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'v.0' => ['nullable', 'string'],")
        ->and($code)->toContain("'v.1' => ['integer'],");
});

it('suppresses the post-prefix items wildcard rules but keeps distinct for uniqueItems', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'uniqueItems' => true,
                    'prefixItems' => [['type' => 'string']],
                    // The post-prefix items schema is NOT enforced: a `v.*`
                    // integer rule would also hit position 0 and false-reject
                    // the spec-valid string there.
                    'items' => ['type' => 'integer'],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'v.0' => ['string'],")
        ->and($code)->toContain("'v.*' => ['distinct'],")
        ->and($code)->not->toContain("'v.*' => ['integer']");
});

it('keeps a multi-type or composition tuple position presence-only', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['type' => ['string', 'integer']],
                        ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                        ['type' => 'boolean'],
                    ],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->not->toContain("'v.0'")
        ->and($code)->not->toContain("'v.1'")
        ->and($code)->toContain("'v.2' => ['boolean'],");
});

it('resolves $ref tuple positions: enum, scalar alias, and object component', function () {
    $files = generatePrefixItemsSchemas([
        'Color' => ['type' => 'string', 'enum' => ['red', 'green']],
        'ShortName' => ['type' => 'string', 'maxLength' => 5],
        'Widget' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        ['$ref' => '#/components/schemas/Color'],
                        ['$ref' => '#/components/schemas/ShortName'],
                        ['$ref' => '#/components/schemas/Widget'],
                    ],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'v.0' => [Rule::enum(Color::class)],")
        ->and($code)->toContain("'v.1' => ['string', 'max:5'],")
        ->and($code)->toContain("'v.2' => ['array'],");
});

it('emits per-index rules for a tuple alias component at its use site', function () {
    $files = generatePrefixItemsSchemas([
        'Pair' => [
            'type' => 'array',
            'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
            'items' => false,
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'pair' => ['$ref' => '#/components/schemas/Pair'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // The non-object array component is inlined at the use site, and its
    // tuple rules come along.
    expect(array_keys($files))->not->toContain('PairData');
    expect($code)->toContain("'pair' => ['sometimes', 'array', 'max:2'],")
        ->and($code)->toContain("'pair.0' => ['string'],")
        ->and($code)->toContain("'pair.1' => ['integer'],");
});

it('skips a malformed position but keeps the later indexes aligned (untrusted input)', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [
                        'garbage',
                        ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->not->toContain("'v.0'")
        ->and($code)->toContain("'v.1' => ['integer'],");
});

it('ignores a non-list prefixItems entirely (untrusted input)', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => 'not-a-list',
                    'items' => ['type' => 'string'],
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // Not a tuple at all: the plain items wildcard path stays in effect.
    expect($code)->toContain("'v.*' => ['string'],")
        ->and($code)->not->toContain("'v.0'");
});

it('keeps the graceful array<int, mixed> typing degradation (rules-only change)', function () {
    $files = generatePrefixItemsSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'v' => [
                    'type' => 'array',
                    'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
                    'items' => false,
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('/** @var array<int, mixed> */')
        ->and($code)->toContain('public readonly ?array $v = null');
});
