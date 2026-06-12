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
 * Issue #110: a request body that is a `$ref` to
 * `#/components/requestBodies/<Name>` resolves to the component and routes
 * through the same content-type logic an inline body takes. A JSON object
 * content schema synthesizes ONE shared `<Component>RequestData` class for
 * every referencing operation; a content schema that is itself a `$ref` to a
 * component schema reuses that component's existing Data class; non-object
 * shapes keep the warned Request fallback; multipart component bodies go
 * through the multipart pipeline with UploadedFile typing.
 *
 * @param  array<string, mixed>  $paths
 * @param  array<string, mixed>  $requestBodies
 * @param  array<string, mixed>  $schemas
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectComponentBody(array $paths, array $requestBodies, array $schemas = []): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
        'components' => ['requestBodies' => $requestBodies],
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
 * Two operations in DIFFERENT tags, both referencing one component body.
 *
 * @return array<string, mixed>
 */
function sharedComponentBodyPaths(): array
{
    return [
        '/pets' => [
            'post' => [
                'tags' => ['Pet'],
                'operationId' => 'createPet',
                'requestBody' => ['$ref' => '#/components/requestBodies/PetBody'],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
        '/shelter-pets' => [
            'post' => [
                'tags' => ['Shelter'],
                'operationId' => 'createShelterPet',
                'requestBody' => ['$ref' => '#/components/requestBodies/PetBody'],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ];
}

it('synthesizes ONE shared class for a component body with an inline object schema used by two operations', function () {
    [$descriptors, $generator, $collector] = collectComponentBody(sharedComponentBodyPaths(), [
        'PetBody' => [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 10],
                    'age' => ['type' => 'integer', 'minimum' => 0],
                ],
            ]]],
        ],
    ]);

    // Both operations type the SAME shared class, named after the component.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'PetBodyRequestData'])
        ->and($descriptors[1]->bodyParam)->toBe(['name' => 'body', 'type' => 'PetBodyRequestData'])
        ->and($descriptors[0]->bodyRequiresRequest)->toBeFalse()
        ->and($descriptors[1]->bodyRequiresRequest)->toBeFalse();

    // Exactly one class in the body bucket, no per-operation duplicates, with
    // the full rules pipeline applied and a component-grained docblock.
    $files = $generator->bodyFiles();
    expect(array_keys($files))->toBe(['PetBodyRequestData']);

    $code = $files['PetBodyRequestData']->code;
    expect($code)->toContain('Request body component "PetBody".')
        ->and($code)->toContain('final class PetBodyRequestData extends Data')
        ->and($code)->toContain("'name' => ['required', 'string', 'max:10']")
        ->and($code)->toContain("'age' => ['sometimes', 'integer', 'min:0']");

    // The referencing operations span two tag groups, so the shared class
    // stays at the flat root (issue #93 multi-group rule) and both grouped
    // controllers import it from there.
    expect($files['PetBodyRequestData']->directory)->toBeNull()
        ->and($descriptors[0]->imports)->toContain('App\\Data\\PetBodyRequestData')
        ->and($descriptors[1]->imports)->toContain('App\\Data\\PetBodyRequestData')
        ->and($collector->warnings())->toBe([]);
});

it('renders the shared component body param into both abstract controllers', function () {
    [$descriptors] = collectComponentBody(sharedComponentBodyPaths(), [
        'PetBody' => [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ]]],
        ],
    ]);

    $controllers = (new ControllerGenerator(new ServerOptions))->generate($descriptors);

    expect($controllers['AbstractPetController']->code)
        ->toContain('use App\Data\PetBodyRequestData;')
        ->toContain('abstract public function store(PetBodyRequestData $body): JsonResponse;');
    expect($controllers['AbstractShelterController']->code)
        ->toContain('use App\Data\PetBodyRequestData;')
        ->toContain('abstract public function store(PetBodyRequestData $body): JsonResponse;');
});

it('places a component body class in the single tag group its referencing operations share', function () {
    $paths = sharedComponentBodyPaths();
    // Same component, but now both operations carry the SAME tag.
    $paths['/shelter-pets']['post']['tags'] = ['Pet'];

    [$descriptors, $generator] = collectComponentBody($paths, [
        'PetBody' => [
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ]]],
        ],
    ]);

    expect($generator->bodyFiles()['PetBodyRequestData']->directory)->toBe('Pet')
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\PetBodyRequestData');
});

it('reuses the existing Data class when the component body content schema is a $ref to a component schema', function () {
    [$descriptors, $generator, $collector] = collectComponentBody(
        sharedComponentBodyPaths(),
        [
            'PetBody' => [
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

    // No synthesis at all: the param is typed with the component schema's
    // existing Data class, exactly like a schema-level $ref body.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'pet', 'type' => 'PetData'])
        ->and($descriptors[1]->bodyParam)->toBe(['name' => 'pet', 'type' => 'PetData'])
        ->and($generator->bodyFiles())->toBe([])
        ->and($collector->warnings())->toBe([]);
});

it('prefers the write variant when the component body wraps a $ref to a read/write split schema', function () {
    [$descriptors] = collectComponentBody(
        sharedComponentBodyPaths(),
        [
            'PetBody' => [
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
            ],
        ],
        [
            'Pet' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'name' => ['type' => 'string'],
                ],
            ],
        ],
    );

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'pet', 'type' => 'PetWritableData']);
});

it('keeps the warned Request fallback for a component body with a non-object schema', function () {
    [$descriptors, $generator, $collector] = collectComponentBody(sharedComponentBodyPaths(), [
        'PetBody' => [
            'content' => ['application/json' => ['schema' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ]]],
        ],
    ]);

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($descriptors[1]->bodyParam)->toBeNull()
        ->and($descriptors[1]->bodyRequiresRequest)->toBeTrue()
        ->and($descriptors[0]->imports)->toContain('Illuminate\\Http\\Request')
        ->and($generator->bodyFiles())->toBe([]);

    // EVERY referencing operation warns: the fallback is per-operation
    // information, and the warning names the component.
    $warnings = [...$generator->warnings(), ...$collector->warnings()];
    expect($warnings)->toContain(
        'Operation POST /pets: the request body component "PetBody" was not generated as a typed Data class (it is not an object schema); the controller method falls back to Illuminate\Http\Request.',
    )->toContain(
        'Operation POST /shelter-pets: the request body component "PetBody" was not generated as a typed Data class (it is not an object schema); the controller method falls back to Illuminate\Http\Request.',
    );
});

it('synthesizes one shared multipart class for a multipart component body', function () {
    [$descriptors, $generator, $collector] = collectComponentBody(sharedComponentBodyPaths(), [
        'PetBody' => [
            'content' => ['multipart/form-data' => ['schema' => [
                'type' => 'object',
                'required' => ['photo'],
                'properties' => [
                    'photo' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/png'],
                    'caption' => ['type' => 'string', 'maxLength' => 50],
                ],
            ]]],
        ],
    ]);

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'PetBodyRequestData'])
        ->and($descriptors[1]->bodyParam)->toBe(['name' => 'body', 'type' => 'PetBodyRequestData']);

    $files = $generator->bodyFiles();
    expect(array_keys($files))->toBe(['PetBodyRequestData']);

    $code = $files['PetBodyRequestData']->code;
    expect($code)->toContain('Multipart request body component "PetBody".')
        ->and($code)->toContain('public readonly UploadedFile $photo')
        ->and($code)->toContain("'photo' => ['required', 'file', 'mimetypes:image/png']")
        ->and($code)->toContain("'caption' => ['sometimes', 'string', 'max:50']")
        ->and($collector->warnings())->toBe([]);
});

it('keeps the warned Request fallback for an unresolvable component body $ref', function () {
    [$descriptors, , $collector] = collectComponentBody(
        [
            '/pets' => [
                'post' => [
                    'tags' => ['Pet'],
                    'operationId' => 'createPet',
                    'requestBody' => ['$ref' => '#/components/requestBodies/Missing'],
                    'responses' => ['201' => ['description' => 'ok']],
                ],
            ],
        ],
        [
            'PetBody' => [
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
        ],
    );

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($collector->warnings())->toContain(
            'Operation POST /pets: the request body $ref ("#/components/requestBodies/Missing") does not resolve to a component request body; the controller method falls back to Illuminate\Http\Request.',
        );
});
