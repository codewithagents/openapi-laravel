<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Build a minimal OpenAPI document from a components.schemas map and generate.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateSchemas(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return (new ModelGenerator)->generate($spec);
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
