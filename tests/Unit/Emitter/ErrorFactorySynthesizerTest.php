<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Unit coverage for the per-operation `<Operation>Errors` factory synthesizer,
 * wired exactly like the planner (generate() then collect() with the generator
 * wired in) and inspecting $generator->errorFactoryFiles().
 */

/**
 * Generate + collect an in-memory document, returning the wired generator and
 * collector so the factory files, support set, and warnings can all be read.
 *
 * @param  array<string, mixed>  $document
 * @return array{0: ModelGenerator, 1: OperationCollector}
 */
function factoriesFor(array $document): array
{
    $spec = (new OpenApiReader)->read($document);
    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $collector->collect($spec);

    return [$generator, $collector];
}

/**
 * A one-operation error document: a GET whose declared error responses are
 * $status => componentSchema, over a shared component pool.
 *
 * @param  array<string, string>  $errors  status code => component schema name
 * @param  array<string, array<string, mixed>>  $schemas  component name => schema
 * @return array<string, mixed>
 */
function errorDoc(array $errors, array $schemas, string $tag = 'things'): array
{
    $responses = ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Ok']]]]];
    foreach ($errors as $status => $component) {
        $responses[(string) $status] = ['description' => 'e', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$component]]]];
    }

    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'E', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => ['tags' => [$tag], 'operationId' => 'getThing', 'responses' => $responses]]],
        'components' => ['schemas' => ['Ok' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]] + $schemas],
    ];
}

$problem = ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]];

it('emits one static factory method per named-component object error slot', function () use ($problem) {
    [$generator] = factoriesFor(errorDoc(['404' => 'Problem'], ['Problem' => $problem]));

    $files = $generator->errorFactoryFiles();
    expect($files)->toHaveKey('GetThingErrors');

    $code = $files['GetThingErrors']->code;
    expect($code)->toContain('final class GetThingErrors')
        ->and($code)->toContain('use App\Data\Support\ApiError;')
        ->and($code)->toContain('public static function notFound(string $message): ApiError')
        ->and($code)->toContain('return new ApiError(new ProblemData(message: $message), 404);');

    // ApiError is inlined because a factory class was actually emitted.
    expect($generator->supportFiles())->toHaveKey('ApiError');
});

it('emits two independent methods when one schema is shared across two statuses', function () use ($problem) {
    [$generator] = factoriesFor(errorDoc(['400' => 'Problem', '404' => 'Problem'], ['Problem' => $problem]));

    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;

    // Both methods forward to the SAME Data class at their OWN status.
    expect($code)->toContain('public static function badRequest(string $message): ApiError')
        ->and($code)->toContain('return new ApiError(new ProblemData(message: $message), 400);')
        ->and($code)->toContain('public static function notFound(string $message): ApiError')
        ->and($code)->toContain('return new ApiError(new ProblemData(message: $message), 404);');
});

it('forwards to a distinct Data class per status when the schemas differ', function () {
    [$generator] = factoriesFor(errorDoc(
        ['400' => 'BadRequestProblem', '404' => 'NotFoundProblem'],
        [
            'BadRequestProblem' => ['type' => 'object', 'required' => ['reason'], 'properties' => ['reason' => ['type' => 'string']]],
            'NotFoundProblem' => ['type' => 'object', 'required' => ['resource'], 'properties' => ['resource' => ['type' => 'string']]],
        ],
    ));

    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;

    expect($code)->toContain('return new ApiError(new BadRequestProblemData(reason: $reason), 400);')
        ->and($code)->toContain('return new ApiError(new NotFoundProblemData(resource: $resource), 404);');
});

it('flattens an array-of-DTO property to a bare array parameter with an @param docblock and no DataCollectionOf', function () {
    [$generator] = factoriesFor(errorDoc(
        ['422' => 'ValidationProblem'],
        [
            'Violation' => ['type' => 'object', 'required' => ['field'], 'properties' => ['field' => ['type' => 'string']]],
            'ValidationProblem' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => [
                    'message' => ['type' => 'string'],
                    'violations' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Violation']],
                ],
            ],
        ],
    ));

    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;

    expect($code)->toContain('@param  array<int, ViolationData>  $violations')
        ->and($code)->toContain('public static function unprocessable(string $message, ?array $violations = null): ApiError')
        ->and($code)->toContain('return new ApiError(new ValidationProblemData(message: $message, violations: $violations), 422);')
        ->and($code)->not->toContain('DataCollectionOf');
});

it('emits methods for the qualifying slots only and warns per skipped slot (partially-qualifying operation)', function () use ($problem) {
    $document = errorDoc(['404' => 'Problem'], ['Problem' => $problem]);
    // Add a non-qualifying inline-object 422 slot alongside the qualifying 404.
    $document['paths']['/things']['get']['responses']['422'] = [
        'description' => 'inline',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]]]],
    ];

    [$generator, $collector] = factoriesFor($document);

    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;
    expect($code)->toContain('public static function notFound(')
        ->and($code)->not->toContain('unprocessable');

    expect(implode("\n", $collector->warnings()))
        ->toContain('Operation GET /things: the 422 error response did not get a throwable factory method');
});

it('skips a discriminated-union error slot while still emitting the sibling plain-object method', function () use ($problem) {
    // A named-component oneOf+discriminator schema is a Data class in the
    // registry (an abstract morphable base), but it carries no captured
    // constructor model, so it cannot be flattened into a factory method: the
    // 400 slot is skipped (with a discriminated-specific warning) while the
    // plain-object 404 slot still gets its notFound() method. This pins the
    // constructorParamsFor() guard against a base/variant double-emission.
    [$generator, $collector] = factoriesFor(errorDoc(
        ['400' => 'ErrorUnion', '404' => 'Problem'],
        [
            'Problem' => $problem,
            'ErrorUnion' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/CatError'],
                    ['$ref' => '#/components/schemas/DogError'],
                ],
                'discriminator' => [
                    'propertyName' => 'kind',
                    'mapping' => ['cat' => '#/components/schemas/CatError', 'dog' => '#/components/schemas/DogError'],
                ],
            ],
            'CatError' => ['type' => 'object', 'required' => ['kind', 'meow'], 'properties' => ['kind' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
            'DogError' => ['type' => 'object', 'required' => ['kind', 'bark'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
        ],
    ));

    $files = $generator->errorFactoryFiles();
    expect($files)->toHaveKey('GetThingErrors');

    $code = $files['GetThingErrors']->code;
    expect($code)->toContain('public static function notFound(string $message): ApiError')
        ->and($code)->toContain('return new ApiError(new ProblemData(message: $message), 404);')
        ->and($code)->not->toContain('badRequest')
        ->and($code)->not->toContain('ErrorUnion');

    expect(implode("\n", $collector->warnings()))->toContain(
        'Operation GET /things: the 400 error response did not get a throwable factory method (its schema resolves to a discriminated-union base or variant, which has no flattenable constructor).',
    );
});

it('flattens the READ variant of an error target: keeps plain and readOnly params, drops writeOnly', function () {
    // Error responses are server OUTPUT, so the factory targets the READ
    // variant: a readOnly property stays (it is returned to the client), a
    // writeOnly property is dropped (it is only ever client input).
    [$generator] = factoriesFor(errorDoc(
        ['404' => 'ProblemRW'],
        [
            'ProblemRW' => [
                'type' => 'object',
                'required' => ['detail'],
                'properties' => [
                    'detail' => ['type' => 'string'],
                    'traceId' => ['type' => 'string', 'readOnly' => true],
                    'debugToken' => ['type' => 'string', 'writeOnly' => true],
                ],
            ],
        ],
    ));

    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;

    expect($code)->toContain('public static function notFound(string $detail, ?string $traceId = null): ApiError')
        ->and($code)->toContain('return new ApiError(new ProblemRWData(detail: $detail, traceId: $traceId), 404);')
        ->and($code)->not->toContain('debugToken');
});

it('emits no factory and does not mark ApiError when no slot qualifies', function () {
    // A scalar (non-object) error schema, and an unresolvable inline object only.
    [$generator] = factoriesFor(errorDoc(
        ['404' => 'ScalarError'],
        ['ScalarError' => ['type' => 'string']],
    ));

    expect($generator->errorFactoryFiles())->toBe([])
        ->and($generator->supportFiles())->not->toHaveKey('ApiError');
});

it('does not mark ApiError when the only error slot is an inline object (deferred in v1)', function () {
    $document = errorDoc([], []);
    $document['paths']['/things']['get']['responses']['404'] = [
        'description' => 'inline',
        'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]]]],
    ];

    [$generator] = factoriesFor($document);

    expect($generator->errorFactoryFiles())->toBe([])
        ->and($generator->supportFiles())->not->toHaveKey('ApiError');
});

it('never emits a factory method for a 1xx or 3xx response, even with an object schema', function () use ($problem) {
    [$generator] = factoriesFor(errorDoc(['100' => 'Problem', '304' => 'Problem'], ['Problem' => $problem]));

    expect($generator->errorFactoryFiles())->toBe([]);
});

it('omits the default and 4XX/5XX wildcard slots entirely in v1, and stays silent when they are the ONLY error slots', function () use ($problem) {
    $document = errorDoc([], ['Problem' => $problem]);
    $document['paths']['/things']['get']['responses']['default'] = ['description' => 'd', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
    $document['paths']['/things']['get']['responses']['4XX'] = ['description' => 'w', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];

    [$generator, $collector] = factoriesFor($document);

    // No concrete 4xx/5xx slot, so no factory at all (default/wildcard omitted),
    // no ApiError inlined, and NO warning (a factory-less operation is silent,
    // to avoid flooding specs whose error bodies are entirely default/wildcard).
    expect($generator->errorFactoryFiles())->toBe([])
        ->and($generator->supportFiles())->not->toHaveKey('ApiError')
        ->and(implode("\n", $collector->warnings()))->not->toContain('did not get a throwable factory method');
});

it('warns for a deferred default and 4XX wildcard slot on an operation that DOES get a factory', function () use ($problem) {
    $document = errorDoc(['404' => 'Problem'], ['Problem' => $problem]);
    $document['paths']['/things']['get']['responses']['default'] = ['description' => 'd', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
    $document['paths']['/things']['get']['responses']['4XX'] = ['description' => 'w', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];

    [$generator, $collector] = factoriesFor($document);

    // The concrete 404 gets a notFound() method; the default and 4XX wildcard
    // slots are deferred in v1, and because the operation DID get a factory they
    // are warned (consistent with the inline-object/non-object skip warnings).
    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;
    expect($code)->toContain('public static function notFound(');

    $warnings = implode("\n", $collector->warnings());
    expect($warnings)
        ->toContain('Operation GET /things: the default error response did not get a throwable factory method')
        ->toContain('Operation GET /things: the 4XX error response did not get a throwable factory method');
});

it('falls back to statusNNN for a concrete code not in the status-name table', function () use ($problem) {
    [$generator] = factoriesFor(errorDoc(['480' => 'Problem'], ['Problem' => $problem]));

    $code = $generator->errorFactoryFiles()['GetThingErrors']->code;
    expect($code)->toContain('public static function status480(string $message): ApiError')
        ->and($code)->toContain('return new ApiError(new ProblemData(message: $message), 480);');
});

it('reuses the committed api-error.yaml fixture: base, shared, and distinct operations', function () {
    $spec = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/server/api-error.yaml');
    $generator = new ModelGenerator;
    $generator->generate($spec);
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($spec);

    $files = $generator->errorFactoryFiles();

    expect(array_keys($files))->toBe(['CreatePetErrors', 'GetPetByIdErrors', 'UpdatePetErrors'])
        ->and($files)->each->toBeInstanceOf(GeneratedFile::class);

    // Shared schema across statuses: badRequest and notFound both -> PetErrorData.
    expect($files['UpdatePetErrors']->code)
        ->toContain('return new ApiError(new PetErrorData(message: $message), 400);')
        ->toContain('return new ApiError(new PetErrorData(message: $message), 404);');

    // Distinct schema per status.
    expect($files['CreatePetErrors']->code)
        ->toContain('return new ApiError(new BadRequestProblemData(reason: $reason), 400);')
        ->toContain('return new ApiError(new NotFoundProblemData(resource: $resource), 404);');
});
