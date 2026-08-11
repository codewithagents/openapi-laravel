<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Issue #174: a POST whose selected success response is exactly 200 and whose
 * return type serializes through spatie's ResponsableData answers 201 Created
 * at runtime, because `calculateResponseStatus()` derives the status from the
 * HTTP verb. The #64 gate attaches no status middleware for a declared 200, so
 * the contract said 200, the runtime said 201, and nothing reported it.
 *
 * The generator now WARNS (the behaviour is deliberately unchanged, see the
 * issue's option 1). These tests pin the exact predicate: only POST, only an
 * exactly-200 declaration, and only a Responsable return.
 *
 * @param  array<string, mixed>  $responses
 * @return array{0: OperationDescriptor, 1: OperationCollector}
 */
function collectStatusWarningOperation(string $method, array $responses): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                $method => [
                    'tags' => ['Thing'],
                    'operationId' => 'doThing',
                    'responses' => $responses,
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                'Other' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
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

/**
 * A JSON response wrapping the given schema, for brevity in the matrix below.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function statusWarningJsonResponse(array $schema): array
{
    return [
        'description' => 'ok',
        'content' => ['application/json' => ['schema' => $schema]],
    ];
}

/**
 * The one message under test, spelled out in full so an accidental reword is a
 * failing test rather than a silent change to a user-facing diagnostic. It is
 * ONE document-level warning naming every affected operation, not one warning
 * per operation.
 */
function postCreatedWarning(int $count, string ...$labels): string
{
    return sprintf(
        'Document: %d POST operation(s) declare a 200 success response and return a Data object, so spatie/laravel-data answers 201 Created (it derives the response status from the HTTP verb) while the contract promises 200, and a declared 200 attaches no status middleware to reconcile them: %s. Declare 201 instead of the 200 so the generated route enforces it, or keep the 200 and enforce it with your own middleware, applied from outside the generated routes file (editing that file fails the drift gate).',
        $count,
        implode(', ', $labels),
    );
}

/** The single-operation form every fixture below produces. */
const POST_CREATED_WARNING_LABELS = ['POST /things'];

/**
 * Every #174 diagnostic the collector emitted, isolated from the rest of the
 * channel. A silent case then asserts the DIAGNOSTIC IS ABSENT, rather than
 * that one exact string is absent, which a reword or a near-miss variant
 * could dodge while still spamming the user.
 *
 * @return list<string>
 */
function postCreatedWarnings(OperationCollector $collector): array
{
    return array_values(array_filter(
        $collector->warnings(),
        static fn (string $warning): bool => str_contains($warning, '201 Created'),
    ));
}

it('warns for a POST whose declared 200 returns a Data class (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing']),
    ]);

    // The behaviour is deliberately unchanged: still no status middleware.
    expect($descriptor->returnType)->toBe('ThingData')
        ->and($descriptor->successStatus)->toBe(200)
        ->and($descriptor->needsStatusMiddleware())->toBeFalse()
        ->and(postCreatedWarnings($collector))->toBe([postCreatedWarning(1, ...POST_CREATED_WARNING_LABELS)]);
});

it('warns for a POST returning a DataCollection, which is Responsable too (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => statusWarningJsonResponse([
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/Thing'],
        ]),
    ]);

    // Spatie\LaravelData\DataCollection uses the same ResponsableData trait,
    // so a POST returning one answers 201 exactly like a single Data object.
    expect($descriptor->returnType)->toBe('DataCollection')
        ->and(postCreatedWarnings($collector))->toBe([postCreatedWarning(1, ...POST_CREATED_WARNING_LABELS)]);
});

it('warns for a POST returning a union of Data classes (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => statusWarningJsonResponse([
            'oneOf' => [
                ['$ref' => '#/components/schemas/Thing'],
                ['$ref' => '#/components/schemas/Other'],
            ],
        ]),
    ]);

    // Every member of the union is a generated Data class, so whichever one
    // the handler returns serializes through ResponsableData.
    expect($descriptor->returnType)->toBe('ThingData|OtherData')
        ->and(postCreatedWarnings($collector))->toBe([postCreatedWarning(1, ...POST_CREATED_WARNING_LABELS)]);
});

it('warns for a POST returning a synthesized inline response Data class (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => statusWarningJsonResponse([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ]),
    ]);

    // The inline object response (issue #129) synthesizes a Data class, which
    // is just as Responsable as a component-$ref one.
    expect($descriptor->returnType)->toBe('DoThingResponseData')
        ->and(postCreatedWarnings($collector))->toBe([postCreatedWarning(1, ...POST_CREATED_WARNING_LABELS)]);
});

it('warns for a POST that declares 200 alongside 201 (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '201' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Other']),
        '200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing']),
    ]);

    // The smallest 2xx wins (issue #64), so the 200 is selected and typed
    // while the runtime answers the 201 whose (different) schema the generated
    // method cannot return. Exactly the divergence worth reporting.
    expect($descriptor->returnType)->toBe('ThingData')
        ->and($descriptor->successStatus)->toBe(200)
        ->and(postCreatedWarnings($collector))->toBe([postCreatedWarning(1, ...POST_CREATED_WARNING_LABELS)]);
});

it('stays silent for a POST that already declares 201 (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '201' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing']),
    ]);

    // A declared 201 matches the framework default AND carries the middleware.
    expect($descriptor->successStatus)->toBe(201)
        ->and($descriptor->needsStatusMiddleware())->toBeTrue()
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for a POST declaring 202, which the middleware already enforces (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '202' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing']),
    ]);

    // RespondsWithStatus:202 normalizes spatie's 201 down to the declared 202,
    // so the contract is already enforced (issue #125).
    expect($descriptor->needsStatusMiddleware())->toBeTrue()
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for a POST returning void (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '204' => ['description' => 'no content'],
    ]);

    // A `void` return never reaches ResponsableData: there is no Data object
    // to serialize, and the 204 middleware guarantees the empty body anyway.
    expect($descriptor->returnType)->toBe('void')
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for a POST 200 that falls back to JsonResponse (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => statusWarningJsonResponse([
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]),
    ]);

    // A non-object inline response keeps the warned JsonResponse fallback, and
    // a JsonResponse carries whatever status the implementer put on it: they
    // own the status, so there is nothing to report.
    expect($descriptor->returnType)->toBe('JsonResponse')
        ->and($descriptor->successStatus)->toBe(200)
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for a POST 200 with no declared content (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => ['description' => 'ok'],
    ]);

    expect($descriptor->returnType)->toBe('JsonResponse')
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for a POST 200 typed as the base Response (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '200' => [
            'description' => 'a PDF',
            'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ],
    ]);

    // A non-JSON-only response is typed as the base Symfony Response
    // (issues #117/#118); the implementer constructs it and its status.
    expect($descriptor->returnType)->toBe('Response')
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for a POST whose success status is only a 2XX wildcard (issue #174)', function () {
    [$descriptor, $collector] = collectStatusWarningOperation('post', [
        '2XX' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing']),
    ]);

    // A range wildcard declares no concrete status, so there is no 200 to
    // contradict and nothing to enforce.
    expect($descriptor->successStatus)->toBeNull()
        ->and(postCreatedWarnings($collector))->toBe([]);
});

it('stays silent for every non-POST verb returning a Data class on 200 (issue #174)', function (string $method) {
    [$descriptor, $collector] = collectStatusWarningOperation($method, [
        '200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing']),
    ]);

    // calculateResponseStatus() answers 201 for POST and 200 for every other
    // verb, so only POST can contradict a declared 200.
    expect($descriptor->returnType)->toBe('ThingData')
        ->and($descriptor->successStatus)->toBe(200)
        ->and($collector->warnings())->toBe([]);
})->with(['get', 'put', 'patch', 'delete']);

it('reports ONE document-level warning naming every operation, sorted (issue #174)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/zebras' => [
                'post' => [
                    'tags' => ['Zebra'],
                    'operationId' => 'addZebra',
                    'responses' => ['200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing'])],
                ],
            ],
            '/apples' => [
                'post' => [
                    'tags' => ['Apple'],
                    'operationId' => 'addApple',
                    'responses' => ['200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing'])],
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
    $collector->collect($spec);

    // ONE warning for the whole document, not one per operation, naming both
    // operations with the labels SORTED (the /apples one first, whatever order
    // the paths were declared in) so the message is byte-stable across runs of
    // the same spec. The frozen corpus baseline hashes this text, so an
    // unstable order would be a flaky gate, not just untidy output.
    expect(postCreatedWarnings($collector))
        ->toBe([postCreatedWarning(2, 'POST /apples', 'POST /zebras')]);
});

it('does not accumulate operations across re-collections (issue #174)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'post' => [
                    'tags' => ['Thing'],
                    'operationId' => 'doThing',
                    'responses' => ['200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing'])],
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

    $collector->collect($spec);
    $collector->collect($spec);

    // The per-operation form was idempotent for free, because the warnings map
    // is KEYED BY MESSAGE. The aggregate is built from a LIST, so collect()
    // must clear it explicitly or a second run reports "2 POST operation(s)"
    // and names /things twice.
    expect(postCreatedWarnings($collector))
        ->toBe([postCreatedWarning(1, ...POST_CREATED_WARNING_LABELS)]);
});

it('keeps the diagnostic to one warning however many operations match (issue #174)', function () {
    $paths = [];
    foreach (['/gamma', '/alpha', '/beta', '/delta', '/epsilon'] as $path) {
        $paths[$path] = [
            'post' => [
                'tags' => ['Thing'],
                'operationId' => 'add'.ucfirst(ltrim($path, '/')),
                'responses' => ['200' => statusWarningJsonResponse(['$ref' => '#/components/schemas/Thing'])],
            ],
        ];
    }

    $spec = (new OpenApiReader)->read([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
        'components' => [
            'schemas' => [
                'Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ],
        ],
    ]);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $collector->collect($spec);

    // The whole point of aggregating: the explanatory paragraph appears ONCE,
    // so it cannot bury the scarcer diagnostics (security middleware, media
    // types) that share this sorted channel. Five matching operations, one
    // warning, five labels.
    expect(postCreatedWarnings($collector))->toHaveCount(1)
        ->and(postCreatedWarnings($collector))->toBe([
            postCreatedWarning(5, 'POST /alpha', 'POST /beta', 'POST /delta', 'POST /epsilon', 'POST /gamma'),
        ]);
});
