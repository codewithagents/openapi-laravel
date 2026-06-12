<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Issue #111: a selected success response that is a `$ref` to
 * `#/components/responses/<Name>` resolves to the component and routes
 * through the same content-type logic an inline response takes. A JSON
 * content schema that is itself a `$ref` to a component schema reuses that
 * component's existing Data class as the return type; an inline object
 * schema synthesizes ONE shared `<Component>ResponseData` class (READ
 * variant: responses are server output) for every referencing operation;
 * non-object shapes keep the warned JsonResponse fallback; a 204 `$ref`
 * stays `void` with no resolution attempt.
 *
 * @param  array<string, mixed>  $paths
 * @param  array<string, mixed>  $responses
 * @param  array<string, mixed>  $schemas
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectComponentResponse(array $paths, array $responses, array $schemas = []): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
        'components' => ['responses' => $responses],
    ];
    if ($schemas !== []) {
        $document['components']['schemas'] = $schemas;
    }

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);

    return [$descriptors, $generator, $collector];
}

/**
 * Two operations in DIFFERENT tags, both referencing one component response.
 *
 * @return array<string, mixed>
 */
function sharedComponentResponsePaths(): array
{
    return [
        '/pets' => [
            'get' => [
                'tags' => ['Pet'],
                'operationId' => 'listPets',
                'responses' => ['200' => ['$ref' => '#/components/responses/PetPage']],
            ],
        ],
        '/shelter-pets' => [
            'get' => [
                'tags' => ['Shelter'],
                'operationId' => 'listShelterPets',
                'responses' => ['200' => ['$ref' => '#/components/responses/PetPage']],
            ],
        ],
    ];
}

it('synthesizes ONE shared class for a component response with an inline object schema used by two operations', function () {
    [$descriptors, $generator, $collector] = collectComponentResponse(sharedComponentResponsePaths(), [
        'PetPage' => [
            'description' => 'A page of pets',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['total'],
                'properties' => [
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'cursor' => ['type' => 'string', 'maxLength' => 64],
                ],
            ]]],
        ],
    ]);

    // Both operations return the SAME shared class, named after the component.
    expect($descriptors[0]->returnType)->toBe('PetPageResponseData')
        ->and($descriptors[1]->returnType)->toBe('PetPageResponseData')
        ->and($descriptors[0]->successStatus)->toBe(200)
        ->and($descriptors[1]->successStatus)->toBe(200);

    // Exactly one class in the response bucket, no per-operation duplicates,
    // with the full rules pipeline applied and a component-grained docblock.
    $files = $generator->responseFiles();
    expect(array_keys($files))->toBe(['PetPageResponseData']);

    $code = $files['PetPageResponseData']->code;
    expect($code)->toContain('Response component "PetPage".')
        ->and($code)->toContain('final class PetPageResponseData extends Data')
        ->and($code)->toContain("'total' => ['required', 'integer', 'min:0']")
        ->and($code)->toContain("'cursor' => ['sometimes', 'string', 'max:64']");

    // The referencing operations span two tag groups, so the shared class
    // stays at the flat root (issue #93 multi-group rule) and both grouped
    // controllers import it from there.
    expect($files['PetPageResponseData']->directory)->toBeNull()
        ->and($descriptors[0]->imports)->toContain('App\\Data\\PetPageResponseData')
        ->and($descriptors[1]->imports)->toContain('App\\Data\\PetPageResponseData')
        ->and($collector->warnings())->toBe([]);
});

it('renders the shared component response return type into both abstract controllers', function () {
    [$descriptors] = collectComponentResponse(sharedComponentResponsePaths(), [
        'PetPage' => [
            'description' => 'A page of pets',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['total' => ['type' => 'integer']],
            ]]],
        ],
    ]);

    $controllers = (new ControllerGenerator(new ServerOptions))->generate($descriptors);

    expect($controllers['AbstractPetController']->code)
        ->toContain('use App\Data\PetPageResponseData;')
        ->toContain('abstract public function index(): PetPageResponseData;');
    expect($controllers['AbstractShelterController']->code)
        ->toContain('use App\Data\PetPageResponseData;')
        ->toContain('abstract public function index(): PetPageResponseData;');
});

it('places a component response class in the single tag group its referencing operations share', function () {
    $paths = sharedComponentResponsePaths();
    // Same component, but now both operations carry the SAME tag.
    $paths['/shelter-pets']['get']['tags'] = ['Pet'];

    [$descriptors, $generator] = collectComponentResponse($paths, [
        'PetPage' => [
            'description' => 'A page of pets',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['total' => ['type' => 'integer']],
            ]]],
        ],
    ]);

    expect($generator->responseFiles()['PetPageResponseData']->directory)->toBe('Pet')
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\PetPageResponseData');
});

it('emits the READ variant for a component response with readOnly/writeOnly split fields', function () {
    [, $generator] = collectComponentResponse(sharedComponentResponsePaths(), [
        'PetPage' => [
            'description' => 'A page of pets',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'secret' => ['type' => 'string', 'writeOnly' => true],
                    'name' => ['type' => 'string'],
                ],
            ]]],
        ],
    ]);

    // A response is server OUTPUT: readOnly fields stay, writeOnly fields are
    // dropped, the opposite of the write variant a request body takes.
    $code = $generator->responseFiles()['PetPageResponseData']->code;
    expect($code)->toContain('$id')
        ->and($code)->toContain('$name')
        ->and($code)->not->toContain('$secret');
});

it('reuses the existing Data class when the component response content schema is a $ref to a component schema', function () {
    [$descriptors, $generator, $collector] = collectComponentResponse(
        sharedComponentResponsePaths(),
        [
            'PetPage' => [
                'description' => 'One pet',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
            ],
        ],
        [
            'Pet' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string']],
            ],
        ],
    );

    // No synthesis at all: the return is typed with the component schema's
    // existing Data class, exactly like a schema-level $ref response.
    expect($descriptors[0]->returnType)->toBe('PetData')
        ->and($descriptors[1]->returnType)->toBe('PetData')
        ->and($generator->responseFiles())->toBe([])
        ->and($collector->warnings())->toBe([]);
});

it('keeps the oneOf union return when the component response wraps a union of Data-class $refs', function () {
    [$descriptors, $generator] = collectComponentResponse(
        sharedComponentResponsePaths(),
        [
            'PetPage' => [
                'description' => 'Cat or dog',
                'content' => ['application/json' => ['schema' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/Cat'],
                        ['$ref' => '#/components/schemas/Dog'],
                    ],
                ]]],
            ],
        ],
        [
            'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'boolean']]],
            'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'boolean']]],
        ],
    );

    // The union path runs against the resolved content unchanged (issue #64
    // semantics preserved); no shared class is synthesized for a union.
    expect($descriptors[0]->returnType)->toBe('CatData|DogData')
        ->and($generator->responseFiles())->toBe([]);
});

it('keeps the warned JsonResponse fallback for a component response with a non-object schema', function () {
    [$descriptors, $generator, $collector] = collectComponentResponse(sharedComponentResponsePaths(), [
        'PetPage' => [
            'description' => 'A bare list',
            'content' => ['application/json' => ['schema' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ]]],
        ],
    ]);

    expect($descriptors[0]->returnType)->toBe('JsonResponse')
        ->and($descriptors[1]->returnType)->toBe('JsonResponse')
        ->and($descriptors[0]->imports)->toContain('Illuminate\\Http\\JsonResponse')
        ->and($generator->responseFiles())->toBe([]);

    // EVERY referencing operation warns: the fallback is per-operation
    // information, and the warning names the component.
    $warnings = [...$generator->warnings(), ...$collector->warnings()];
    expect($warnings)->toContain(
        'Operation GET /pets: the response component "PetPage" was not generated as a typed Data class (it is not an object schema); the return type falls back to JsonResponse.',
    )->toContain(
        'Operation GET /shelter-pets: the response component "PetPage" was not generated as a typed Data class (it is not an object schema); the return type falls back to JsonResponse.',
    );
});

it('prefers the JSON media type when the component response declares several', function () {
    [$descriptors, $generator] = collectComponentResponse(sharedComponentResponsePaths(), [
        'PetPage' => [
            'description' => 'JSON or CSV',
            'content' => [
                'text/csv' => ['schema' => ['type' => 'string']],
                'application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['total' => ['type' => 'integer']],
                ]],
            ],
        ],
    ]);

    // The JSON media type wins exactly as it does for an inline response;
    // the CSV alternative never degrades the typed return.
    expect($descriptors[0]->returnType)->toBe('PetPageResponseData')
        ->and(array_keys($generator->responseFiles()))->toBe(['PetPageResponseData']);
});

it('keeps a resolved component response without JSON content a silent JsonResponse fallback', function () {
    [$descriptors, , $collector] = collectComponentResponse(sharedComponentResponsePaths(), [
        'PetPage' => [
            'description' => 'Raw bytes only',
            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ],
    ]);

    // Same silent behavior as an inline non-JSON response: nothing to type,
    // nothing to warn about.
    expect($descriptors[0]->returnType)->toBe('JsonResponse')
        ->and($collector->warnings())->toBe([]);
});

it('stays void with no resolution attempt for a 204 component response $ref', function () {
    [$descriptors, $generator, $collector] = collectComponentResponse(
        [
            '/pets/{petId}' => [
                'delete' => [
                    'tags' => ['Pet'],
                    'operationId' => 'deletePet',
                    'parameters' => [
                        ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => ['204' => ['$ref' => '#/components/responses/Empty']],
                ],
            ],
        ],
        [
            'Empty' => ['description' => 'no content'],
        ],
    );

    expect($descriptors[0]->returnType)->toBe('void')
        ->and($descriptors[0]->successStatus)->toBe(204)
        ->and($generator->responseFiles())->toBe([])
        ->and($collector->warnings())->toBe([]);
});

it('keeps the warned JsonResponse fallback for an unresolvable component response $ref', function () {
    [$descriptors, $generator, $collector] = collectComponentResponse(
        [
            '/pets' => [
                'get' => [
                    'tags' => ['Pet'],
                    'operationId' => 'listPets',
                    'responses' => ['200' => ['$ref' => '#/components/responses/Missing']],
                ],
            ],
        ],
        [
            'PetPage' => [
                'description' => 'unused',
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
        ],
    );

    expect($descriptors[0]->returnType)->toBe('JsonResponse')
        ->and($descriptors[0]->successStatus)->toBe(200)
        ->and($generator->responseFiles())->toBe([])
        ->and($collector->warnings())->toContain(
            'Operation GET /pets: the "200" response $ref ("#/components/responses/Missing") does not resolve to a component response; the return type falls back to JsonResponse.',
        );
});

it('resolves a default-response $ref into a typed return when no 2xx is declared', function () {
    [$descriptors, $generator] = collectComponentResponse(
        [
            '/pets' => [
                'get' => [
                    'tags' => ['Pet'],
                    'operationId' => 'listPets',
                    'responses' => ['default' => ['$ref' => '#/components/responses/PetPage']],
                ],
            ],
        ],
        [
            'PetPage' => [
                'description' => 'A page of pets',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['total' => ['type' => 'integer']],
                ]]],
            ],
        ],
    );

    // The default fallback carries no declared success status (issue #64),
    // but the resolved component still types the return.
    expect($descriptors[0]->returnType)->toBe('PetPageResponseData')
        ->and($descriptors[0]->successStatus)->toBeNull()
        ->and(array_keys($generator->responseFiles()))->toBe(['PetPageResponseData']);
});
