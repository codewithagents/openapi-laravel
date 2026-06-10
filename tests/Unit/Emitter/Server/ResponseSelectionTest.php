<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;

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

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

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

    // Smallest 2xx wins: the typed 200 body, not the empty 204.
    expect($descriptor->returnType)->toBe('PData')
        ->and($descriptor->returnDoc)->toBeNull();
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
