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
function generateUnionSchemas(array $schemas): array
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

it('emits a native union plus a variant docblock for a scalar oneOf', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // Source-order union, deduped, with a docblock listing the variants.
    expect($code)->toContain('/** @var string|int */')
        ->and($code)->toContain('public readonly string|int|null $value = null')
        // Presence-only rules: no type rule that would wrongly reject a valid variant.
        ->and($code)->toContain("'value' => ['sometimes']");
});

it('types an undiscriminated object union as mixed, keeping the variant docblock (issue #31)', function () {
    $files = generateUnionSchemas([
        'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]],
        'Pet' => [
            'type' => 'object',
            'properties' => [
                'animal' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['$ref' => '#/components/schemas/Dog'],
                ]],
            ],
        ],
    ]);

    $code = $files['PetData']->code;

    // A native `CatData|DogData` property type makes laravel-data infer nested
    // rules from the union and, lacking a discriminator, validate every payload
    // against the FIRST variant, false-rejecting a valid non-first variant
    // (issue #31). The interim fix types the property `mixed` (presence-only, no
    // false-reject) while preserving the variant union in the `@var` docblock.
    expect($code)->toContain('/** @var CatData|DogData */')
        ->and($code)->toContain('public readonly mixed $animal = null')
        ->and($code)->not->toContain('CatData|DogData $animal');
});

it('types a mixed scalar+object union as mixed, keeping the variant docblock (issue #31)', function () {
    $files = generateUnionSchemas([
        'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string'],
                    ['$ref' => '#/components/schemas/Cat'],
                ]],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // A union that includes any Data-class member triggers the same nested-rule
    // inference, so it is typed `mixed` too. Scalar-only unions are unaffected.
    expect($code)->toContain('/** @var string|CatData */')
        ->and($code)->toContain('public readonly mixed $value = null')
        ->and($code)->not->toContain('string|CatData $value');
});

it('adds null to the union when a member is the null type', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [['type' => 'string'], ['type' => 'null']]],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // The null member contributes nullability and a null docblock variant.
    expect($code)->toContain('/** @var string|null */')
        ->and($code)->toContain('public readonly string|null $value = null')
        ->and($code)->toContain("'value' => ['sometimes', 'nullable']");
});

it('adds null to the union when a member schema is itself nullable', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string', 'nullable' => true],
                    ['type' => 'integer'],
                ]],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain('public readonly string|int|null $value = null')
        ->toContain('/** @var string|int|null */');
});

it('keeps a required union without a null default when nothing is nullable', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
            'required' => ['value'],
        ],
    ]);

    $code = $files['HolderData']->code;

    // Required: no '|null', no '= null' default, and a 'required' presence rule.
    expect($code)->toContain('public readonly string|int $value,')
        ->and($code)->not->toContain('public readonly string|int|null $value')
        ->and($code)->toContain("'value' => ['required']");
});

it('falls back to mixed when a union member is itself a oneOf', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string'],
                    ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]],
                ]],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // A nested union is not a clean member: the whole property collapses to mixed.
    expect($code)->toContain('public readonly mixed $value = null')
        ->and($code)->not->toContain('/** @var');
});

it('falls back to mixed when a union member is an empty (untyped) schema', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [['type' => 'string'], new stdClass]],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain('public readonly mixed $value = null');
});

it('falls back to mixed when a union member is an array type', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string']],
                ]],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain('public readonly mixed $value = null');
});

it('treats anyOf identically to oneOf for typing', function () {
    $files = generateUnionSchemas([
        'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]],
        'Pet' => [
            'type' => 'object',
            'properties' => [
                'animal' => ['anyOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['$ref' => '#/components/schemas/Dog'],
                ]],
            ],
        ],
    ]);

    $code = $files['PetData']->code;

    // anyOf of object refs is the same undiscriminated object union: mixed type,
    // variant docblock preserved (issue #31).
    expect($code)->toContain('/** @var CatData|DogData */')
        ->and($code)->toContain('public readonly mixed $animal = null');
});

it('dedupes repeated members in the union, keeping source order', function () {
    $files = generateUnionSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                    ['type' => 'string'],
                ]],
            ],
        ],
    ]);

    // 'string' appears once despite two string members; order is string|int.
    expect($files['HolderData']->code)->toContain('/** @var string|int */');
});

it('falls back to mixed when a member $ref points at an enum, not a Data class', function () {
    $files = generateUnionSchemas([
        'Color' => ['type' => 'string', 'enum' => ['red', 'green']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string'],
                    ['$ref' => '#/components/schemas/Color'],
                ]],
            ],
        ],
    ]);

    // An enum ref is not a clean object-union member: fall back to mixed.
    expect($files['HolderData']->code)->toContain('public readonly mixed $value = null');
});

it('is deterministic: regenerating a union spec produces byte-identical output', function () {
    $schemas = [
        'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'pet' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['$ref' => '#/components/schemas/Dog'],
                ]],
                'scalar' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
        ],
    ];

    $first = array_map(fn ($f) => $f->code, generateUnionSchemas($schemas));
    $second = array_map(fn ($f) => $f->code, generateUnionSchemas($schemas));

    expect($first)->toBe($second);
});
