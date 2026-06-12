<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Build a minimal OpenAPI document from a components.schemas map and generate.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateSchemas(array $schemas): array
{
    return allOfGenerator($schemas)[1];
}

/**
 * Build a minimal OpenAPI document from a components.schemas map, generate, and
 * return both the generator (for registry inspection) and the emitted files.
 *
 * @param  array<string, mixed>  $schemas
 * @return array{0: ModelGenerator, 1: array<string, GeneratedFile>}
 */
function allOfGenerator(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $files = $generator->generate($spec);

    return [$generator, $files];
}

it('merges allOf of two inline objects into one flat class', function () {
    $files = generateSchemas([
        'Combined' => [
            'allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
            ],
        ],
    ]);

    $code = $files['CombinedData']->code;

    expect($code)->toContain('public readonly ?string $a = null')
        ->and($code)->toContain('public readonly ?int $b = null');
});

it('merges a $ref member, exposing the referenced properties (Extended_Price)', function () {
    $files = generateSchemas([
        'Price' => [
            'type' => 'object',
            'properties' => [
                'amount' => ['type' => 'integer'],
                'currency' => ['type' => 'string'],
            ],
            'required' => ['amount'],
        ],
        'Extended_Price' => [
            'allOf' => [
                ['type' => 'object', 'properties' => ['billingCurrency' => ['type' => 'string']]],
                ['$ref' => '#/components/schemas/Price'],
            ],
        ],
    ]);

    $code = $files['ExtendedPriceData']->code;

    // billingCurrency from the inline member plus every Price property are present.
    expect($code)->toContain('$billingCurrency')
        ->and($code)->toContain('$amount')
        ->and($code)->toContain('$currency')
        // the required member field is enforced as required.
        ->and($code)->toContain("'amount' => ['required'");
});

it('keeps a $ref member as its own standalone class while also merging it', function () {
    $files = generateSchemas([
        'Price' => [
            'type' => 'object',
            'properties' => ['amount' => ['type' => 'integer']],
        ],
        'Extended_Price' => [
            'allOf' => [
                ['type' => 'object', 'properties' => ['billingCurrency' => ['type' => 'string']]],
                ['$ref' => '#/components/schemas/Price'],
            ],
        ],
    ]);

    // Standalone PriceData still emitted; merged ExtendedPriceData also present.
    expect(array_keys($files))->toContain('PriceData', 'ExtendedPriceData');
});

it('merges the schema own properties together with the allOf members', function () {
    $files = generateSchemas([
        'Profile' => [
            'type' => 'object',
            'properties' => ['own' => ['type' => 'string']],
            'allOf' => [
                ['type' => 'object', 'properties' => ['fromMember' => ['type' => 'string']]],
            ],
        ],
    ]);

    $code = $files['ProfileData']->code;

    expect($code)->toContain('$own')
        ->and($code)->toContain('$fromMember');
});

it('unions required[] from multiple members so rules() marks all required', function () {
    $files = generateSchemas([
        'Combined' => [
            'allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'required' => ['b']],
            ],
        ],
    ]);

    $code = $files['CombinedData']->code;

    expect($code)->toContain("'a' => ['required', 'string']")
        ->and($code)->toContain("'b' => ['required', 'string']");
});

it('flattens a nested allOf (a member that itself uses allOf)', function () {
    $files = generateSchemas([
        'Base' => [
            'type' => 'object',
            'properties' => ['base' => ['type' => 'string']],
        ],
        'Middle' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Base'],
                ['type' => 'object', 'properties' => ['middle' => ['type' => 'string']]],
            ],
        ],
        'Outer' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Middle'],
                ['type' => 'object', 'properties' => ['outer' => ['type' => 'string']]],
            ],
        ],
    ]);

    $code = $files['OuterData']->code;

    expect($code)->toContain('$base')
        ->and($code)->toContain('$middle')
        ->and($code)->toContain('$outer');
});

it('resolves conflicts so the own property and later members win the value', function () {
    $files = generateSchemas([
        'Combined' => [
            'type' => 'object',
            // own "shared" wins the value (string) over both members.
            'properties' => ['shared' => ['type' => 'string']],
            'allOf' => [
                ['type' => 'object', 'properties' => ['shared' => ['type' => 'integer']]],
                ['type' => 'object', 'properties' => ['onlyLater' => ['type' => 'boolean']]],
            ],
        ],
    ]);

    $code = $files['CombinedData']->code;

    // shared is declared once, and as string (own wins), not int.
    expect(substr_count($code, '$shared'))->toBe(1)
        ->and($code)->toContain('?string $shared')
        ->and($code)->not->toContain('?int $shared')
        ->and($code)->toContain('$onlyLater');
});

it('preserves first-seen property order: own then member1 then member2', function () {
    $files = generateSchemas([
        'Combined' => [
            'type' => 'object',
            'properties' => ['own' => ['type' => 'string']],
            'allOf' => [
                ['type' => 'object', 'properties' => ['first' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['second' => ['type' => 'string']]],
            ],
        ],
    ]);

    $code = $files['CombinedData']->code;

    $own = strpos($code, '$own');
    $first = strpos($code, '$first');
    $second = strpos($code, '$second');

    expect($own)->toBeLessThan($first)
        ->and($first)->toBeLessThan($second);
});

it('resolves a single $ref wrapped in allOf to the referenced class (alias)', function () {
    $files = generateSchemas([
        'Inner' => [
            'type' => 'object',
            'properties' => ['x' => ['type' => 'string']],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                // allOf:[$ref] + description is an alias, not a fresh inline copy.
                'inner' => [
                    'allOf' => [['$ref' => '#/components/schemas/Inner']],
                    'description' => 'aliased ref',
                ],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly ?InnerData $inner = null');
});

it('does not infinitely recurse on a self-referential allOf property', function () {
    $files = generateSchemas([
        'Node' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'parent' => [
                    'allOf' => [['$ref' => '#/components/schemas/Node']],
                    'description' => 'the parent node',
                ],
            ],
        ],
    ]);

    $code = $files['NodeData']->code;

    expect($code)->toContain('public readonly ?NodeData $parent = null');
});

it('is deterministic: regenerating produces byte-identical output', function () {
    $schemas = [
        'Price' => [
            'type' => 'object',
            'properties' => ['amount' => ['type' => 'integer']],
        ],
        'Extended_Price' => [
            'allOf' => [
                ['type' => 'object', 'properties' => ['billingCurrency' => ['type' => 'string']]],
                ['$ref' => '#/components/schemas/Price'],
            ],
        ],
    ];

    $first = array_map(fn ($f) => $f->code, generateSchemas($schemas));
    $second = array_map(fn ($f) => $f->code, generateSchemas($schemas));

    expect($first)->toBe($second);
});

it('splits a composing schema into read + writable when a merged member carries read/write flags', function () {
    [$generator, $files] = allOfGenerator([
        'Base' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'password' => ['type' => 'string', 'writeOnly' => true],
                'name' => ['type' => 'string'],
            ],
            'required' => ['password'],
        ],
        'Account' => [
            'allOf' => [['$ref' => '#/components/schemas/Base']],
        ],
    ]);

    // Both variants exist because the merged member contributes read/write flags.
    expect(array_keys($files))->toContain('AccountData', 'AccountWritableData');

    $read = $files['AccountData']->code;
    $write = $files['AccountWritableData']->code;

    // readOnly id drops from the write variant; writeOnly password drops from read.
    expect($read)->toContain('$id')
        ->and($read)->not->toContain('$password')
        ->and($write)->toContain('$password')
        ->and($write)->not->toContain('$id')
        ->and($read)->toContain('$name')
        ->and($write)->toContain('$name');

    // The server scaffold maps the write body to the writable variant.
    $registry = $generator->registry();
    expect($registry['Account']['dataClass'])->toBe('AccountData')
        ->and($registry['Account']['writeClass'])->toBe('AccountWritableData');
});

it('merges allOf used inside a property schema (nested address)', function () {
    $files = generateSchemas([
        'BaseAddress' => [
            'type' => 'object',
            'properties' => [
                'street' => ['type' => 'string'],
                'city' => ['type' => 'string'],
            ],
        ],
        'Order' => [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/BaseAddress'],
                        ['type' => 'object', 'properties' => ['deliveryInstructions' => ['type' => 'string']]],
                    ],
                ],
            ],
        ],
    ]);

    // The nested address class carries both BaseAddress props and the inline one.
    $nested = $files['OrderAddressData']->code;

    expect($nested)->toContain('$street')
        ->and($nested)->toContain('$city')
        ->and($nested)->toContain('$deliveryInstructions');
});

it('marks the merged property nullable when only an allOf member is nullable', function () {
    $files = generateSchemas([
        'Inner' => [
            'type' => 'object',
            'nullable' => true,
            'properties' => ['x' => ['type' => 'string']],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                // The property schema itself is not nullable, the member is.
                'inner' => [
                    'allOf' => [['$ref' => '#/components/schemas/Inner']],
                    'description' => 'nullable via the member',
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain('public readonly ?InnerData $inner = null');
});

it('requires a property that is required only in a later member (required union)', function () {
    $files = generateSchemas([
        'A' => [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
        ],
        'B' => [
            'type' => 'object',
            'properties' => ['b' => ['type' => 'string']],
            'required' => ['b'],
        ],
        'Combined' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/A'],
                ['$ref' => '#/components/schemas/B'],
            ],
        ],
    ]);

    $code = $files['CombinedData']->code;

    // b is required (only member B declares it required); a is optional.
    expect($code)->toContain("'b' => ['required', 'string']")
        ->and($code)->toContain("'a' => ['sometimes', 'string']");
});
