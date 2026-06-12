<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Issue #67: every silent degradation to `mixed` or `Illuminate\Http\Request`
 * must hit the warnings channel. These tests pin the warning text per
 * degradation path (the wording is part of the contract: CLI users grep it),
 * prove the dedupe (one warning per distinct cause and schema, so the
 * read/write variants of one schema never double-report), and prove that the
 * generated OUTPUT is unchanged: warnings are diagnostics only.
 */

/**
 * @param  array<string, mixed>  $document
 */
function degradationSpec(array $document): OpenApi
{
    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return $spec;
}

/**
 * @param  array<string, mixed>  $schemas
 * @return array{0: array<string, GeneratedFile>, 1: list<string>}
 */
function degradationModelRun(array $schemas): array
{
    $generator = new ModelGenerator;
    $files = $generator->generate(degradationSpec([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ]));

    return [$files, $generator->warnings()];
}

/**
 * @param  array<string, mixed>  $paths
 * @param  array<string, mixed>  $components
 * @return array{0: list<string>, 1: list<string>} collector warnings, model-generator warnings
 */
function degradationCollectorRun(array $paths, array $components = []): array
{
    $spec = degradationSpec([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
        'components' => $components === [] ? new stdClass : $components,
    ]);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $collector->collect($spec);

    return [$collector->warnings(), $generator->warnings()];
}

// ---------------------------------------------------------------------------
// ModelGenerator: $ref degradations to mixed
// ---------------------------------------------------------------------------

it('warns when a property $ref is external, naming the pointer and the schema', function () {
    [$files, $warnings] = degradationModelRun([
        'Holder' => [
            'type' => 'object',
            'properties' => ['thing' => ['$ref' => './common.yaml#/components/schemas/Thing']],
        ],
    ]);

    expect($warnings)->toContain(
        'Schema "Holder": $ref "./common.yaml#/components/schemas/Thing" is external or not a #/components/schemas pointer and degrades to mixed with presence-only validation. Bundle external references into one document before generating.',
    )
        // Diagnostics only: the established degradation output is unchanged.
        ->and($files['HolderData']->code)->toContain('public readonly mixed $thing');
});

it('warns when a property $ref does not resolve to any generated type', function () {
    [, $warnings] = degradationModelRun([
        'Holder' => [
            'type' => 'object',
            'properties' => ['thing' => ['$ref' => '#/components/schemas/Missing']],
        ],
    ]);

    expect($warnings)->toContain(
        'Schema "Holder": $ref "#/components/schemas/Missing" does not resolve to a generated type and degrades to mixed with presence-only validation.',
    );
});

it('warns for a bare-$ref component entry, naming the component and the pointer', function () {
    [$files, $warnings] = degradationModelRun([
        'AliasEntry' => ['$ref' => '#/components/schemas/Real'],
        'Real' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        'User' => [
            'type' => 'object',
            'properties' => ['alias' => ['$ref' => '#/components/schemas/AliasEntry']],
        ],
    ]);

    expect($files)->not->toHaveKey('AliasEntryData')
        ->and($warnings)->toContain(
            'Component schema "AliasEntry" is a bare $ref ("#/components/schemas/Real") and is not generated; references to it degrade to mixed with presence-only validation.',
        )
        // The use site degrades too and says so independently.
        ->and($warnings)->toContain(
            'Schema "User": $ref "#/components/schemas/AliasEntry" does not resolve to a generated type and degrades to mixed with presence-only validation.',
        );
});

it('warns when a oneOf/anyOf collapses to mixed over a messy inline member', function () {
    [$files, $warnings] = degradationModelRun([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'object', 'properties' => ['nested' => ['type' => 'string']]],
                    ],
                ],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Schema "Holder": a oneOf/anyOf member is not a plain scalar or a $ref to a generated Data class; the union degrades to mixed with presence-only validation.',
    )
        ->and($files['HolderData']->code)->toContain('public readonly mixed $value');
});

it('warns with the pointer when a union member $ref is the messy part', function () {
    [, $warnings] = degradationModelRun([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['$ref' => './common.yaml#/components/schemas/Thing'],
                    ],
                ],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Schema "Holder": a oneOf/anyOf member $ref "./common.yaml#/components/schemas/Thing" does not resolve to a plain scalar or a generated Data class; the union degrades to mixed with presence-only validation.',
    );
});

it('does not warn for the deliberate undiscriminated object-union mixed (issue #31)', function () {
    [$files, $warnings] = degradationModelRun([
        'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'animal' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/Cat'],
                        ['$ref' => '#/components/schemas/Dog'],
                    ],
                ],
            ],
        ],
    ]);

    // The union is visible in the generated docblock and documented as
    // presence-only by design; it is not a SILENT degradation, so no warning.
    expect($files['HolderData']->code)->toContain('CatData|DogData')
        ->and($warnings)->toBe([]);
});

it('warns when an alias chain is cyclic and degrades to mixed', function () {
    [, $warnings] = degradationModelRun([
        'Loop' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Loop'],
                ['type' => 'string'],
            ],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => ['value' => ['$ref' => '#/components/schemas/Loop']],
        ],
    ]);

    expect(implode("\n", $warnings))->toContain(
        'Component schema "Loop" is part of a cyclic alias chain and degrades to mixed with presence-only validation.',
    );
});

// ---------------------------------------------------------------------------
// ModelGenerator: allOf members that vanish from the merge
// ---------------------------------------------------------------------------

it('warns when an external allOf member is not merged', function () {
    [$files, $warnings] = degradationModelRun([
        'Composed' => [
            'allOf' => [
                ['$ref' => './common.yaml#/components/schemas/Thing'],
                ['type' => 'object', 'properties' => ['local' => ['type' => 'string']]],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Schema "Composed": allOf member $ref "./common.yaml#/components/schemas/Thing" is external or not a #/components/schemas pointer; its properties are not merged into the composed class. Bundle external references into one document before generating.',
    )
        // The local member still merges: degraded, not dropped.
        ->and($files['ComposedData']->code)->toContain('public readonly ?string $local');
});

it('warns when a dangling allOf member is not merged', function () {
    [, $warnings] = degradationModelRun([
        'Composed' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Missing'],
                ['type' => 'object', 'properties' => ['local' => ['type' => 'string']]],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Schema "Composed": allOf member $ref "#/components/schemas/Missing" does not resolve to a component schema; its properties are not merged into the composed class.',
    );
});

it('stays silent for an allOf member that is a non-object alias (nothing mergeable, by design)', function () {
    [, $warnings] = degradationModelRun([
        'Name' => ['type' => 'string'],
        'Composed' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Name'],
                ['type' => 'object', 'properties' => ['local' => ['type' => 'string']]],
            ],
        ],
    ]);

    expect(array_filter($warnings, fn (string $w): bool => str_contains($w, 'allOf member')))->toBe([]);
});

// ---------------------------------------------------------------------------
// Dedupe: one warning per distinct cause and schema
// ---------------------------------------------------------------------------

it('emits one warning when the read/write split resolves the same degraded $ref twice', function () {
    [, $warnings] = degradationModelRun([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'secret' => ['type' => 'string', 'writeOnly' => true],
                'thing' => ['$ref' => './common.yaml#/components/schemas/Thing'],
            ],
        ],
    ]);

    $external = array_values(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, './common.yaml#/components/schemas/Thing'),
    ));

    // HolderData and HolderWritableData both resolve the property; the
    // identical cause on the same schema is reported once.
    expect($external)->toHaveCount(1)
        ->and($external[0])->toStartWith('Schema "Holder": ');
});

it('keeps two warnings for the same pointer hit in two distinct schemas', function () {
    [, $warnings] = degradationModelRun([
        'First' => [
            'type' => 'object',
            'properties' => ['thing' => ['$ref' => './common.yaml#/components/schemas/Thing']],
        ],
        'Second' => [
            'type' => 'object',
            'properties' => ['thing' => ['$ref' => './common.yaml#/components/schemas/Thing']],
        ],
    ]);

    $external = array_values(array_filter(
        $warnings,
        fn (string $w): bool => str_contains($w, './common.yaml#/components/schemas/Thing'),
    ));

    expect($external)->toHaveCount(2)
        ->and($external[0])->toStartWith('Schema "First": ')
        ->and($external[1])->toStartWith('Schema "Second": ');
});

// ---------------------------------------------------------------------------
// OperationCollector: Request / JsonResponse fallbacks
// ---------------------------------------------------------------------------

it('warns when the request body is a $ref into components.requestBodies', function () {
    [$warnings] = degradationCollectorRun([
        '/pets' => [
            'post' => [
                'operationId' => 'createPet',
                'requestBody' => ['$ref' => '#/components/requestBodies/PetBody'],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ], [
        'requestBodies' => [
            'PetBody' => [
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Operation POST /pets: the request body is a $ref ("#/components/requestBodies/PetBody") and component request bodies are not resolved yet; the controller method falls back to Illuminate\Http\Request.',
    );
});

it('stays silent for an inline object request body, which is now generated (issue #76)', function () {
    [$collectorWarnings, $modelWarnings] = degradationCollectorRun([
        '/pets' => [
            'post' => [
                'operationId' => 'createPet',
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                        ],
                    ],
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ]);

    // The inline object body synthesizes CreatePetRequestData and types the
    // controller param, so nothing degrades and nothing may warn.
    expect($collectorWarnings)->toBe([])
        ->and($modelWarnings)->toBe([]);
});

it('warns when an inline request body is not an object shape (the Request fallback stays)', function () {
    [$collectorWarnings, $modelWarnings] = degradationCollectorRun([
        '/users' => [
            'post' => [
                'operationId' => 'bulkUsers',
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'responses' => ['200' => ['description' => 'ok']],
            ],
        ],
    ]);

    // The skip is reported through the model generator's channel (it owns the
    // body pipeline, mirroring the query-parameter skips), not the collector's.
    expect($modelWarnings)->toContain(
        'Operation POST /users: the inline request body schema was not generated as a typed Data class (it is not an object schema); the controller method falls back to Illuminate\Http\Request.',
    )->and($collectorWarnings)->toBe([]);
});

it('warns when an inline request body is a free-form object map', function () {
    [, $modelWarnings] = degradationCollectorRun([
        '/settings' => [
            'put' => [
                'operationId' => 'putSettings',
                'requestBody' => [
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                        ],
                    ],
                ],
                'responses' => ['200' => ['description' => 'ok']],
            ],
        ],
    ]);

    expect($modelWarnings)->toContain(
        'Operation PUT /settings: the inline request body schema was not generated as a typed Data class (it is an object map with only additionalProperties, which resolves to a typed array, not a Data class); the controller method falls back to Illuminate\Http\Request.',
    );
});

it('warns when the request body schema is inline and no model generator is wired in (legacy call sites)', function () {
    $spec = degradationSpec([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/pets' => [
                'post' => [
                    'operationId' => 'createPet',
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                            ],
                        ],
                    ],
                    'responses' => ['201' => ['description' => 'ok']],
                ],
            ],
        ],
    ]);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    // No model generator wired in (the legacy, pre-#63 wiring): the collector
    // cannot synthesize the body class, so the degradation is its own to report.
    $collector = new OperationCollector(new ServerOptions, $generator->registry());
    $collector->collect($spec);

    expect($collector->warnings())->toContain(
        'Operation POST /pets: the request body schema is inline (not a $ref to a component schema) and no model generator is wired in to synthesize a Data class; the controller method falls back to Illuminate\Http\Request.',
    );
});

it('warns when the request body $ref is external or dangling', function () {
    [$warnings] = degradationCollectorRun([
        '/a' => [
            'post' => [
                'operationId' => 'postA',
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => ['$ref' => './common.yaml#/components/schemas/ThingInput']]],
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
        '/b' => [
            'post' => [
                'operationId' => 'postB',
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]],
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Operation POST /a: the request body $ref "./common.yaml#/components/schemas/ThingInput" is external or not a #/components/schemas pointer; the controller method falls back to Illuminate\Http\Request.',
    )->toContain(
        'Operation POST /b: the request body $ref "#/components/schemas/Missing" does not resolve to a generated Data class; the controller method falls back to Illuminate\Http\Request.',
    );
});

it('warns when the selected success response is a $ref into components.responses', function () {
    [$warnings] = degradationCollectorRun([
        '/pets' => [
            'get' => [
                'operationId' => 'listPets',
                'responses' => ['200' => ['$ref' => '#/components/responses/PetList']],
            ],
        ],
    ], [
        'responses' => [
            'PetList' => ['description' => 'ok'],
        ],
    ]);

    expect($warnings)->toContain(
        'Operation GET /pets: the "200" response is a $ref ("#/components/responses/PetList") and component responses are not resolved yet; the return type falls back to JsonResponse.',
    );
});

it('stays silent for a $ref response selected at 204 (the method is void either way)', function () {
    [$warnings] = degradationCollectorRun([
        '/pets/{petId}' => [
            'delete' => [
                'operationId' => 'deletePet',
                'parameters' => [
                    ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'responses' => ['204' => ['$ref' => '#/components/responses/Empty']],
            ],
        ],
    ], [
        'responses' => [
            'Empty' => ['description' => 'no content'],
        ],
    ]);

    expect($warnings)->toBe([]);
});

it('warns when the default response is a bypassed $ref', function () {
    [$warnings] = degradationCollectorRun([
        '/pets' => [
            'get' => [
                'operationId' => 'listPets',
                'responses' => ['default' => ['$ref' => '#/components/responses/Anything']],
            ],
        ],
    ], [
        'responses' => [
            'Anything' => ['description' => 'ok'],
        ],
    ]);

    expect($warnings)->toContain(
        'Operation GET /pets: the "default" response is a $ref ("#/components/responses/Anything") and component responses are not resolved yet; the return type falls back to JsonResponse.',
    );
});

it('warns when a response schema $ref is external', function () {
    [$warnings] = degradationCollectorRun([
        '/things' => [
            'get' => [
                'operationId' => 'listThings',
                'responses' => [
                    '200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => ['$ref' => './common.yaml#/components/schemas/Thing']]],
                    ],
                ],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Operation GET /things: the response schema $ref "./common.yaml#/components/schemas/Thing" is external or not a #/components/schemas pointer; the return type falls back to JsonResponse.',
    );
});

it('warns for an external and for a dangling parameter $ref, naming each pointer', function () {
    [$warnings] = degradationCollectorRun([
        '/items/{itemId}' => [
            'get' => [
                'operationId' => 'getItem',
                'parameters' => [
                    ['$ref' => './common.yaml#/components/parameters/ItemId'],
                    ['$ref' => '#/components/parameters/DoesNotExist'],
                ],
                'responses' => ['204' => ['description' => 'ok']],
            ],
        ],
    ]);

    expect($warnings)->toContain(
        'Operation GET /items/{itemId}: parameter $ref "./common.yaml#/components/parameters/ItemId" is external or not a #/components/parameters pointer; the parameter is ignored.',
    )->toContain(
        'Operation GET /items/{itemId}: parameter $ref "#/components/parameters/DoesNotExist" does not resolve to a component parameter; the parameter is ignored.',
    );
});

it('attributes a degraded query-parameter $ref to the operation, not a schema', function () {
    [, $modelWarnings] = degradationCollectorRun([
        '/search' => [
            'get' => [
                'operationId' => 'search',
                'parameters' => [
                    [
                        'name' => 'cursor',
                        'in' => 'query',
                        'schema' => ['$ref' => './common.yaml#/components/schemas/Cursor'],
                    ],
                ],
                'responses' => ['204' => ['description' => 'ok']],
            ],
        ],
    ]);

    expect($modelWarnings)->toContain(
        'Query parameters of operation GET /search: $ref "./common.yaml#/components/schemas/Cursor" is external or not a #/components/schemas pointer and degrades to mixed with presence-only validation. Bundle external references into one document before generating.',
    );
});

// ---------------------------------------------------------------------------
// The multi-file fixture: external $refs warn everywhere they hollow output
// ---------------------------------------------------------------------------

it('surfaces every external-$ref degradation of a multi-file spec instead of hollowing output silently', function () {
    $spec = (new SpecParser)->parseFile(__DIR__.'/../../Fixtures/multifile/main.yaml');

    $generator = new ModelGenerator;
    $files = $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $collector->collect($spec);

    $modelWarnings = implode("\n", $generator->warnings());
    $collectorWarnings = implode("\n", $collector->warnings());

    expect($modelWarnings)->toContain(
        'Schema "Holder": $ref "./common.yaml#/components/schemas/Thing" is external',
    )
        ->and($modelWarnings)->toContain(
            'Schema "Composed": allOf member $ref "./common.yaml#/components/schemas/Thing" is external',
        )
        ->and($collectorWarnings)->toContain(
            'Operation POST /things: the request body $ref "./common.yaml#/components/schemas/ThingInput" is external',
        )
        ->and($collectorWarnings)->toContain(
            'Operation GET /things: the response schema $ref "./common.yaml#/components/schemas/Thing" is external',
        )
        // And the degraded output itself is unchanged: hollow but valid.
        ->and($files['HolderData']->code)->toContain('public readonly mixed $thing');
});
