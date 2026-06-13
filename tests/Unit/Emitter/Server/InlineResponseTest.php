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
 * Issue #129: an INLINE (non-$ref) JSON object success-response schema
 * synthesizes a per-operation Data class (`<Operation>ResponseData`) through
 * the model generator's emission pipeline and types the return, the symmetric
 * twin of the inline request body (issue #76) and the inline counterpart of
 * the component response (issue #116). The READ variant is emitted (responses
 * are server output: readOnly kept, writeOnly dropped). Non-object inline
 * shapes keep the documented JsonResponse fallback, and a 204 stays void.
 *
 * @param  array<string, mixed>  $paths
 * @param  array<string, mixed>  $schemas
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectInlineResponse(array $paths, array $schemas = []): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
    if ($schemas !== []) {
        $document['components'] = ['schemas' => $schemas];
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
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
function inlineResponsePaths(array $response): array
{
    return [
        '/pets' => [
            'get' => [
                'tags' => ['Pet'],
                'operationId' => 'listPets',
                'responses' => $response,
            ],
        ],
    ];
}

it('synthesizes a per-operation ResponseData class for an inline object 2xx response', function () {
    [$descriptors, $generator, $collector] = collectInlineResponse(inlineResponsePaths([
        '200' => [
            'description' => 'A pet',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['total'],
                'properties' => [
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'cursor' => ['type' => 'string', 'maxLength' => 64],
                ],
            ]]],
        ],
    ]));

    expect($descriptors[0]->returnType)->toBe('ListPetsResponseData')
        ->and($descriptors[0]->successStatus)->toBe(200)
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\ListPetsResponseData')
        ->and($descriptors[0]->imports)->not->toContain('Illuminate\\Http\\JsonResponse');

    $files = $generator->responseFiles();
    expect(array_keys($files))->toBe(['ListPetsResponseData']);

    $code = $files['ListPetsResponseData']->code;
    expect($code)->toContain('Response of GET /pets.')
        ->and($code)->toContain('final class ListPetsResponseData extends Data')
        ->and($code)->toContain("'total' => ['required', 'integer', 'min:0']")
        ->and($code)->toContain("'cursor' => ['sometimes', 'string', 'max:64']")
        ->and($collector->warnings())->toBe([]);
});

it('renders the synthesized inline response return type into the abstract controller', function () {
    [$descriptors] = collectInlineResponse(inlineResponsePaths([
        '200' => [
            'description' => 'A pet',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['total' => ['type' => 'integer']],
            ]]],
        ],
    ]));

    $controllers = (new ControllerGenerator(new ServerOptions))->generate($descriptors);

    expect($controllers['AbstractPetController']->code)
        ->toContain('use App\Data\Pet\ListPetsResponseData;')
        ->toContain('abstract public function index(): ListPetsResponseData;');
});

it('places the inline response class in the operation tag group', function () {
    [$descriptors, $generator] = collectInlineResponse(inlineResponsePaths([
        '200' => [
            'description' => 'A pet',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['total' => ['type' => 'integer']],
            ]]],
        ],
    ]));

    expect($generator->responseFiles()['ListPetsResponseData']->directory)->toBe('Pet')
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\ListPetsResponseData');
});

it('emits the READ variant for an inline response with readOnly/writeOnly split fields', function () {
    [, $generator] = collectInlineResponse(inlineResponsePaths([
        '200' => [
            'description' => 'A pet',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'secret' => ['type' => 'string', 'writeOnly' => true],
                    'name' => ['type' => 'string'],
                ],
            ]]],
        ],
    ]));

    // A response is server OUTPUT: readOnly fields stay, writeOnly fields are
    // dropped, the opposite of the write variant a request body takes.
    $code = $generator->responseFiles()['ListPetsResponseData']->code;
    expect($code)->toContain('$id')
        ->and($code)->toContain('$name')
        ->and($code)->not->toContain('$secret');
});

it('keeps the warned JsonResponse fallback for an inline non-object 2xx response', function () {
    [$descriptors, $generator, $collector] = collectInlineResponse(inlineResponsePaths([
        '200' => [
            'description' => 'A bare list',
            'content' => ['application/json' => ['schema' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ]]],
        ],
    ]));

    expect($descriptors[0]->returnType)->toBe('JsonResponse')
        ->and($descriptors[0]->successStatus)->toBe(200)
        ->and($descriptors[0]->imports)->toContain('Illuminate\\Http\\JsonResponse')
        ->and($generator->responseFiles())->toBe([]);

    $warnings = [...$generator->warnings(), ...$collector->warnings()];
    expect($warnings)->toContain(
        'Operation GET /pets: the inline response schema was not generated as a typed Data class (it is not an object schema); the return type falls back to JsonResponse.',
    );
});

it('keeps a scalar inline 2xx response on the warned JsonResponse fallback', function () {
    [$descriptors, $generator, $collector] = collectInlineResponse(inlineResponsePaths([
        '200' => [
            'description' => 'A count',
            'content' => ['application/json' => ['schema' => ['type' => 'integer']]],
        ],
    ]));

    expect($descriptors[0]->returnType)->toBe('JsonResponse')
        ->and($generator->responseFiles())->toBe([]);

    $warnings = [...$generator->warnings(), ...$collector->warnings()];
    expect($warnings)->toContain(
        'Operation GET /pets: the inline response schema was not generated as a typed Data class (it is not an object schema); the return type falls back to JsonResponse.',
    );
});

it('stays void for an inline 204 response with no synthesis attempt', function () {
    [$descriptors, $generator, $collector] = collectInlineResponse([
        '/pets/{petId}' => [
            'delete' => [
                'tags' => ['Pet'],
                'operationId' => 'deletePet',
                'parameters' => [
                    ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'responses' => ['204' => ['description' => 'no content']],
            ],
        ],
    ]);

    expect($descriptors[0]->returnType)->toBe('void')
        ->and($descriptors[0]->successStatus)->toBe(204)
        ->and($generator->responseFiles())->toBe([])
        ->and($collector->warnings())->toBe([]);
});

it('keeps the spec status middleware for a non-200 inline object success (#64)', function () {
    [$descriptors, $generator, $collector] = collectInlineResponse(inlineResponsePaths([
        '201' => [
            'description' => 'created',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ]]],
        ],
    ]));

    // The inline object still synthesizes a typed return, AND the selected
    // non-200 status rides along so the route gets the RespondsWithStatus
    // middleware (issue #64 semantics preserved).
    expect($descriptors[0]->returnType)->toBe('ListPetsResponseData')
        ->and($descriptors[0]->successStatus)->toBe(201)
        ->and(array_keys($generator->responseFiles()))->toBe(['ListPetsResponseData'])
        ->and($collector->warnings())->toBe([]);
});

it('emits a deterministic response class name across repeated runs', function () {
    $paths = inlineResponsePaths([
        '200' => [
            'description' => 'A pet',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['total' => ['type' => 'integer']],
            ]]],
        ],
    ]);

    [, $first] = collectInlineResponse($paths);
    [, $second] = collectInlineResponse($paths);

    expect($first->responseFiles()['ListPetsResponseData']->code)
        ->toBe($second->responseFiles()['ListPetsResponseData']->code);
});
