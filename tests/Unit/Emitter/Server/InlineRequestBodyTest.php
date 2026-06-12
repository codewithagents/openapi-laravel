<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;

/**
 * Issue #76: an inline JSON object request-body schema synthesizes a
 * per-operation Data class (`<Operation>RequestData`) through the model
 * generator's emission pipeline and types the controller param, exactly like
 * a component `$ref` body. Non-object inline shapes keep the documented
 * Request fallback. Naming is deterministic and collision-safe through the
 * run's shared UniqueNames allocator.
 *
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectInlineBody(array $paths, array $schemas = []): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
    if ($schemas !== []) {
        $document['components'] = ['schemas' => $schemas];
    }

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);

    return [$descriptors, $generator, $collector];
}

function inlinePetPaths(array $schema): array
{
    return [
        '/pets' => [
            'post' => [
                'tags' => ['Pet'],
                'operationId' => 'createPet',
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => $schema]],
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ];
}

it('types an inline object request body as a synthesized per-operation Data param', function () {
    [$descriptors, $generator] = collectInlineBody(inlinePetPaths([
        'type' => 'object',
        'required' => ['name'],
        'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 10],
            'age' => ['type' => 'integer', 'minimum' => 0],
        ],
    ]));

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData'])
        ->and($descriptors[0]->bodyRequiresRequest)->toBeFalse()
        ->and($descriptors[0]->imports)->toContain('App\\Data\\CreatePetRequestData')
        ->and($descriptors[0]->imports)->not->toContain('Illuminate\\Http\\Request');

    $files = $generator->bodyFiles();
    expect($files)->toHaveKey('CreatePetRequestData');

    $code = $files['CreatePetRequestData']->code;
    expect($code)->toContain('Request body of POST /pets.')
        ->and($code)->toContain('final class CreatePetRequestData extends Data')
        ->and($code)->toContain('public readonly string $name')
        ->and($code)->toContain("'name' => ['required', 'string', 'max:10']")
        ->and($code)->toContain("'age' => ['sometimes', 'integer', 'min:0']");
});

it('renders the typed body param into the abstract controller signature', function () {
    [$descriptors] = collectInlineBody(inlinePetPaths([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
    ]));

    $controllers = (new ControllerGenerator(new ServerOptions))->generate($descriptors);
    $code = $controllers['AbstractPetController']->code;

    expect($code)->toContain('use App\Data\CreatePetRequestData;')
        ->and($code)->toContain('abstract public function createPet(CreatePetRequestData $body): JsonResponse;');
});

it('emits the nested classes an inline body spawns into the body bucket', function () {
    [, $generator] = collectInlineBody(inlinePetPaths([
        'type' => 'object',
        'properties' => [
            'home' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
            ],
        ],
    ]));

    $files = $generator->bodyFiles();
    expect(array_keys($files))->toBe(['CreatePetRequestData', 'CreatePetRequestHomeData'])
        ->and($files['CreatePetRequestData']->code)->toContain('public readonly ?CreatePetRequestHomeData $home')
        ->and($files['CreatePetRequestHomeData']->code)->toContain('public readonly ?string $city');
});

it('emits the write shape for an inline body with readOnly fields, like a $ref body would', function () {
    [, $generator] = collectInlineBody(inlinePetPaths([
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'name' => ['type' => 'string'],
        ],
    ]));

    $code = $generator->bodyFiles()['CreatePetRequestData']->code;

    // readOnly fields are response-only: the request-side class drops them.
    expect($code)->toContain('public readonly ?string $name')
        ->and($code)->not->toContain('$id');
});

it('suffixes the body class deterministically when a component schema took the name', function () {
    [$descriptors, $generator] = collectInlineBody(
        inlinePetPaths(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]),
        [
            'CreatePetRequest' => [
                'type' => 'object',
                'properties' => ['other' => ['type' => 'string']],
            ],
        ],
    );

    // The component reserved CreatePetRequestData first (components are
    // emitted before operations are collected), so the synthesized body class
    // takes the deterministic _2 suffix through the shared allocator.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData_2'])
        ->and($generator->bodyFiles())->toHaveKey('CreatePetRequestData_2');
});

it('renames the body param when a path parameter already claimed $body', function () {
    [$descriptors] = collectInlineBody([
        '/things/{body}' => [
            'post' => [
                'operationId' => 'createThing',
                'parameters' => [
                    ['name' => 'body', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => ['name' => ['type' => 'string']],
                    ]]],
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ]);

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body_2', 'type' => 'CreateThingRequestData'])
        ->and($descriptors[0]->pathParams)->toBe([['name' => 'body', 'phpType' => 'string']]);
});

it('merges an inline allOf body (a $ref member plus inline properties) into one typed class', function () {
    [$descriptors, $generator] = collectInlineBody(
        inlinePetPaths([
            'allOf' => [
                ['$ref' => '#/components/schemas/Pet'],
                ['type' => 'object', 'properties' => ['extra' => ['type' => 'string']]],
            ],
        ]),
        [
            'Pet' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        ],
    );

    $code = $generator->bodyFiles()['CreatePetRequestData']->code;

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData'])
        ->and($code)->toContain('public readonly string $name')
        ->and($code)->toContain('public readonly ?string $extra');
});

it('keeps the Request fallback for a body that is an allOf alias of a non-object component', function () {
    // allOf: [$ref ScalarAlias] is the chained-alias shape: it merges to an
    // EMPTY class (a scalar has no properties), which would silently drop the
    // whole payload. The component pipeline promotes such a shape to an alias,
    // and a $ref body to an alias falls back to Request, so the inline form
    // mirrors that.
    [$descriptors, $generator] = collectInlineBody(
        inlinePetPaths(['allOf' => [['$ref' => '#/components/schemas/ScalarAlias']]]),
        ['ScalarAlias' => ['type' => 'string', 'format' => 'date-time']],
    );

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($generator->bodyFiles())->toBe([])
        ->and($generator->warnings())->toContain(
            'Operation POST /pets: the inline request body schema was not generated as a typed Data class (it is an allOf alias of a non-object component, so no Data class can type it); the controller method falls back to Illuminate\Http\Request.',
        );
});

it('keeps the Request fallback for a non-object inline body', function () {
    [$descriptors, $generator] = collectInlineBody(inlinePetPaths([
        'type' => 'array',
        'items' => ['type' => 'string'],
    ]));

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($descriptors[0]->imports)->toContain('Illuminate\\Http\\Request')
        ->and($generator->bodyFiles())->toBe([]);
});

it('synthesizes an empty marker class for an inline empty object body, mirroring a $ref to an empty component', function () {
    [$descriptors, $generator] = collectInlineBody(inlinePetPaths(['type' => 'object']));

    // A component {type: object} with no properties is a legitimately empty
    // Data class ($ref bodies type against it), so the inline form mirrors
    // that: the class exists, carries the issue #95 marker, and the empty-body
    // warning names it.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData'])
        ->and($generator->bodyFiles()['CreatePetRequestData']->code)->toContain('// The spec defines no properties for this schema.')
        ->and($generator->warnings())->toContain(
            'Schema "CreatePetRequest" has no properties: the generated class "CreatePetRequestData" has an empty body, so every payload field for this shape is dropped on hydration.',
        );
});

it('does not inject the query class when an inline body occupies the payload (hybrid rule)', function () {
    [$descriptors] = collectInlineBody([
        '/pets' => [
            'post' => [
                'operationId' => 'createPet',
                'parameters' => [
                    ['name' => 'dryRun', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                ],
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => ['name' => ['type' => 'string']],
                    ]]],
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ]);

    // The typed inline body counts as a request body, so the query class is
    // reachable via ::fromQuery($request) only, never container-injected.
    expect($descriptors[0]->bodyParam)->not->toBeNull()
        ->and($descriptors[0]->queryParam)->toBe(['name' => 'query', 'type' => 'CreatePetQueryData', 'injected' => false, 'fqcn' => 'App\\Data\\CreatePetQueryData']);
});

it('does not type an inline body when no model generator is wired in (legacy call sites)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => inlinePetPaths(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]),
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $descriptors = (new OperationCollector(new ServerOptions, $generator->registry()))->collect($spec);

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($generator->bodyFiles())->toBe([]);
});

it('keeps the operationId-derived body class name under laravel-conventions (issue #94)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => inlinePetPaths(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]),
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $descriptors = (new OperationCollector(
        new ServerOptions(laravelConventions: true),
        $generator->registry(),
        null,
        $generator,
    ))->collect($spec);

    // The controller method takes the conventional name (POST on a collection
    // path is `store`), but the Data layer stays operationId-derived exactly
    // like the query classes: Data classes share one global namespace, so a
    // per-controller StoreRequestData would clash across controllers.
    expect($descriptors[0]->methodName)->toBe('store')
        ->and($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData'])
        ->and($generator->bodyFiles())->toHaveKey('CreatePetRequestData');
});

it('generates byte-identical body classes across two runs (determinism)', function () {
    $run = function (): array {
        [, $generator] = collectInlineBody(inlinePetPaths([
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'home' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ]));

        return array_map(fn ($file) => $file->code, $generator->bodyFiles());
    };

    expect($run())->toBe($run());
});
