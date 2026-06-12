<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Regression guards pinning the success-response selection policy. These pass
 * today; the tests lock them so the behaviour cannot silently drift.
 *
 * @param  array<string, mixed>  $responses
 */
function collectSingleOperation(array $responses): OperationDescriptor
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/thing' => [
                'get' => [
                    'tags' => ['Thing'],
                    'operationId' => 'getThing',
                    'responses' => $responses,
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'P' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ],
        ],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $descriptors = (new OperationCollector(new ServerOptions, $generator->registry()))->collect($spec);

    return $descriptors[0];
}

it('selects the 200 JSON body over a co-existing 204', function () {
    $descriptor = collectSingleOperation([
        '204' => ['description' => 'no content'],
        '200' => [
            'description' => 'ok',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/P']]],
        ],
    ]);

    // Smallest 2xx wins: the typed 200 body, not the empty 204. The selected
    // status is the 200, so no status middleware is needed (issue #64).
    expect($descriptor->returnType)->toBe('PData')
        ->and($descriptor->returnDoc)->toBeNull()
        ->and($descriptor->successStatus)->toBe(200)
        ->and($descriptor->needsStatusMiddleware())->toBeFalse();
});

it('reports the smallest 2xx as the enforced success status (issue #64)', function () {
    $descriptor = collectSingleOperation([
        '202' => ['description' => 'accepted later'],
        '201' => [
            'description' => 'created',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/P']]],
        ],
    ]);

    // The same smallest-2xx pick drives BOTH the return type and the status
    // the generated route enforces, so they can never disagree.
    expect($descriptor->returnType)->toBe('PData')
        ->and($descriptor->successStatus)->toBe(201)
        ->and($descriptor->needsStatusMiddleware())->toBeTrue();
});

it('returns void and enforces 204 for a 204-only operation (issue #64)', function () {
    $descriptor = collectSingleOperation([
        '204' => ['description' => 'no content'],
        '404' => ['description' => 'not found'],
    ]);

    expect($descriptor->returnType)->toBe('void')
        ->and($descriptor->returnDoc)->toBeNull()
        ->and($descriptor->successStatus)->toBe(204)
        ->and($descriptor->needsStatusMiddleware())->toBeTrue();
});

it('reports no success status when only a default response exists (issue #64)', function () {
    $descriptor = collectSingleOperation([
        'default' => [
            'description' => 'whatever happens',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/P']]],
        ],
    ]);

    // The default fallback still types the return, but no success status is
    // declared, so the route must not force one.
    expect($descriptor->returnType)->toBe('PData')
        ->and($descriptor->successStatus)->toBeNull()
        ->and($descriptor->needsStatusMiddleware())->toBeFalse();
});

it('enforces a declared 201 even when its body is untyped (issue #64)', function () {
    $descriptor = collectSingleOperation([
        '201' => ['description' => 'created, no body schema'],
    ]);

    // No JSON schema means the JsonResponse fallback, but the spec still
    // declares 201, and the middleware only promotes an exactly-200 response,
    // so an explicit user-set status is never overridden.
    expect($descriptor->returnType)->toBe('JsonResponse')
        ->and($descriptor->successStatus)->toBe(201)
        ->and($descriptor->needsStatusMiddleware())->toBeTrue();
});

it('falls back to JsonResponse for a non-JSON binary success response', function () {
    $descriptor = collectSingleOperation([
        '200' => [
            'description' => 'binary',
            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ],
    ]);

    // A binary body has no application/json schema: degrade to JsonResponse
    // (safe, lossy), never void or mixed.
    expect($descriptor->returnType)->toBe('JsonResponse')
        ->and($descriptor->imports)->toContain('Illuminate\\Http\\JsonResponse');
});
