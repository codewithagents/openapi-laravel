<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Issues #117/#118: a selected success response whose declared content has NO
 * JSON media type (no `application/json`, no `+json` suffix) is typed as the
 * base `Symfony\Component\HttpFoundation\Response` instead of JsonResponse,
 * with a per-operation degradation warning naming the declared media types.
 * Response is the parent of BinaryFileResponse and StreamedResponse, so the
 * implementer can return any subclass; the union would be redundant. JSON
 * wins whenever it is declared alongside anything else (no warning), and a
 * response with no declared content at all keeps the JsonResponse default.
 *
 * @param  array<string, mixed>  $responses
 * @return array{0: OperationDescriptor, 1: OperationCollector}
 */
function collectNonJsonResponse(array $responses): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/things/{thingId}' => [
                'get' => [
                    'tags' => ['Thing'],
                    'operationId' => 'getThing',
                    'parameters' => [
                        ['name' => 'thingId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => $responses,
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ],
        ],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);

    return [$descriptors[0], $collector];
}

it('types a text/html-only response as the base Response with a warning (#117)', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => [
            'description' => 'an HTML page',
            'content' => ['text/html' => ['schema' => ['type' => 'string']]],
        ],
    ]);

    expect($descriptor->returnType)->toBe('Response')
        ->and($descriptor->imports)->toContain('Symfony\\Component\\HttpFoundation\\Response')
        ->and($descriptor->imports)->not->toContain('Illuminate\\Http\\JsonResponse')
        ->and($collector->warnings())->toContain(
            'Operation GET /things/{thingId}: the response declares no JSON media type (text/html); the method returns the base Response type and no typed Data return is generated.',
        );
});

it('types a binary-only response as the base Response with a warning (#118)', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => [
            'description' => 'a download',
            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ],
    ]);

    // The base Response covers BinaryFileResponse and StreamedResponse (both
    // subclasses), so a download endpoint compiles against the signature.
    expect($descriptor->returnType)->toBe('Response')
        ->and($descriptor->imports)->toContain('Symfony\\Component\\HttpFoundation\\Response')
        ->and($collector->warnings())->toContain(
            'Operation GET /things/{thingId}: the response declares no JSON media type (application/octet-stream); the method returns the base Response type and no typed Data return is generated.',
        );
});

it('names every declared media type in the warning, in spec order', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => [
            'description' => 'image or pdf',
            'content' => [
                'image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                'application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                'text/plain' => ['schema' => ['type' => 'string']],
            ],
        ],
    ]);

    expect($descriptor->returnType)->toBe('Response')
        ->and($collector->warnings())->toContain(
            'Operation GET /things/{thingId}: the response declares no JSON media type (image/png, application/pdf, text/plain); the method returns the base Response type and no typed Data return is generated.',
        );
});

it('keeps the typed Data return with no warning when JSON is declared alongside HTML (JSON wins)', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => [
            'description' => 'JSON or HTML',
            'content' => [
                'text/html' => ['schema' => ['type' => 'string']],
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']],
            ],
        ],
    ]);

    expect($descriptor->returnType)->toBe('ThingData')
        ->and($descriptor->imports)->not->toContain('Symfony\\Component\\HttpFoundation\\Response')
        ->and($collector->warnings())->toBe([]);
});

it('keeps a +json vendor suffix routed through JSON typing, never the base Response', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => [
            'description' => 'vendor JSON',
            'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]],
        ],
    ]);

    expect($descriptor->returnType)->toBe('ThingData')
        ->and($collector->warnings())->toBe([]);
});

it('keeps the JsonResponse fallback for a schema-less JSON entry next to non-JSON content (JSON still promised)', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => [
            'description' => 'JSON declared without a schema',
            'content' => [
                'application/json' => [],
                'text/html' => ['schema' => ['type' => 'string']],
            ],
        ],
    ]);

    // The spec still promises JSON; only the schema is missing, so the
    // established JsonResponse fallback stands and nothing warns about the
    // co-declared HTML alternative.
    expect($descriptor->returnType)->toBe('JsonResponse')
        ->and($collector->warnings())->toBe([]);
});

it('keeps the silent JsonResponse fallback for a response with no declared content (deliberate)', function () {
    [$descriptor, $collector] = collectNonJsonResponse([
        '200' => ['description' => 'no content map at all'],
    ]);

    // No declared content says nothing about the body: the established
    // JsonResponse default stands rather than widening every schema-less
    // response to the base Response.
    expect($descriptor->returnType)->toBe('JsonResponse')
        ->and($collector->warnings())->toBe([]);
});

it('renders the base Response import and signature into the abstract controller', function () {
    [$descriptor] = collectNonJsonResponse([
        '200' => [
            'description' => 'a download',
            'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ],
    ]);

    $controllers = (new ControllerGenerator(new ServerOptions))->generate([$descriptor]);

    expect($controllers['AbstractThingController']->code)
        ->toContain('use Symfony\Component\HttpFoundation\Response;')
        ->toContain('abstract public function show(int $thingId): Response;');
});

it('preserves the #64 status enforcement for a non-200 non-JSON response, end to end into the routes file', function () {
    [$descriptor] = collectNonJsonResponse([
        '201' => [
            'description' => 'created, returns plain text',
            'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
        ],
    ]);

    // The status selection and middleware semantics (issue #64) are
    // independent of the return typing: the declared 201 is still enforced.
    expect($descriptor->returnType)->toBe('Response')
        ->and($descriptor->successStatus)->toBe(201)
        ->and($descriptor->needsStatusMiddleware())->toBeTrue();

    // And the guarantee holds in the RENDERED output, not just on the
    // descriptor: the generated route carries the RespondsWithStatus
    // middleware pinned to the declared 201, with the support class
    // imported, exactly as for a JSON-typed operation.
    $routes = (new RouteGenerator(new ServerOptions))->generate([$descriptor]);

    expect($routes->code)->toContain('use App\Data\Support\RespondsWithStatus;')
        ->and($routes->code)->toContain(
            "Route::get('/things/{thingId}', [ThingController::class, 'show'])->name('show')->whereNumber('thingId')->middleware(RespondsWithStatus::class.':201');",
        );
});
