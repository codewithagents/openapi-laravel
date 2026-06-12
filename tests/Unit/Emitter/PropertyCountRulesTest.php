<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;

/**
 * minProperties/maxProperties emission (issue #72). A JSON object arrives as a
 * PHP array and Laravel's `min:`/`max:` count the elements of an array value,
 * so the key-count bounds map directly onto `min:`/`max:` wherever the schema
 * is KNOWN to describe an object: typed/untyped maps (inline and `$ref`),
 * explicit inline `type: object` properties, `$ref`s to explicit object
 * components, and object-shaped map values. Untyped schemas are skipped: their
 * instances may legally be non-objects, where JSON Schema ignores the keywords
 * but Laravel's `min:`/`max:` would measure string length or numeric value and
 * false-reject valid data.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateCountSchemas(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    // Run through the same pre-parse normalization as the real pipeline so the
    // string-coercion test below proves end-to-end behaviour.
    $normalized = SchemaNormalizer::normalize(json_decode((string) json_encode($document), true));

    $spec = Reader::readFromJson((string) json_encode($normalized), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return (new ModelGenerator)->generate($spec);
}

it('emits min/max key-count rules for an inline typed map', function () {
    $files = generateCountSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'scores' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                    'minProperties' => 2,
                    'maxProperties' => 5,
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'scores' => ['sometimes', 'array', 'max:5', 'min:2'],")
        ->toContain("'scores.*' => ['integer'],");
});

it('emits key-count rules for an untyped map (additionalProperties: true)', function () {
    $files = generateCountSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'labels' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'minProperties' => 1,
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'labels' => ['sometimes', 'array', 'min:1'],");
});

it('emits key-count rules at the use site of a $ref to a pure-map component', function () {
    $files = generateCountSchemas([
        'Language' => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
            'minProperties' => 1,
            'maxProperties' => 3,
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'translations' => ['$ref' => '#/components/schemas/Language'],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'translations' => ['sometimes', 'array', 'max:3', 'min:1'],");
});

it('emits key-count rules for an explicit inline object property', function () {
    $files = generateCountSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'meta' => [
                    'type' => 'object',
                    'minProperties' => 1,
                    'maxProperties' => 4,
                    'properties' => [
                        'note' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'meta' => ['sometimes', 'array', 'max:4', 'min:1'],");
});

it('emits key-count rules at the use site of a $ref to an explicit object component', function () {
    $files = generateCountSchemas([
        'Patch' => [
            'type' => 'object',
            'minProperties' => 1,
            'properties' => [
                'name' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'patch' => ['$ref' => '#/components/schemas/Patch'],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'patch' => ['sometimes', 'array', 'min:1'],");
});

it('keeps a $ref object property presence-only when the component declares no count bounds', function () {
    $files = generateCountSchemas([
        'Plain' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'plain' => ['$ref' => '#/components/schemas/Plain'],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'plain' => ['sometimes'],");
});

it('emits key-count rules on an object-shaped map value (wildcard)', function () {
    $files = generateCountSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'groups' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'minProperties' => 1,
                    ],
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'groups.*' => ['array', 'min:1'],");
});

it('skips key-count rules on an untyped schema (could false-reject a non-object instance)', function () {
    $files = generateCountSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                // No type, no properties, no additionalProperties: per JSON
                // Schema a string instance is valid here (minProperties only
                // applies to objects), so Laravel's min: must not be emitted.
                'anything' => ['minProperties' => 5],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'anything' => ['sometimes'],")
        ->not->toContain("'min:5'");
});

it('coerces string-typed minProperties/maxProperties through SchemaNormalizer (#32) and emits the rules', function () {
    $files = generateCountSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'scores' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'integer'],
                    'minProperties' => '2',
                    'maxProperties' => '5',
                ],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'scores' => ['sometimes', 'array', 'max:5', 'min:2'],");
});

it('is deterministic: regenerating key-count rules produces byte-identical output', function () {
    $schemas = [
        'Language' => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
            'minProperties' => 1,
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'translations' => ['$ref' => '#/components/schemas/Language'],
                'meta' => ['type' => 'object', 'maxProperties' => 2],
            ],
        ],
    ];

    $first = generateCountSchemas($schemas);
    $second = generateCountSchemas($schemas);

    foreach ($first as $name => $file) {
        expect($second[$name]->code)->toBe($file->code);
    }
});
