<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Issue #77, end to end through the collector and the route generator: the
 * configured security.middleware_map puts ->middleware([...]) on exactly the
 * routes the spec secures, the operation/global precedence holds in emitted
 * output, the status-enforcing middleware (issue #64) folds into the same
 * array, and every degradation reaches the warnings channel.
 */

/**
 * @param  array<string, mixed>  $document
 * @param  array<string, list<string>>  $middlewareMap
 * @return array{0: string, 1: list<string>} routes file code, collector warnings
 */
function securedRoutesRun(array $document, array $middlewareMap = []): array
{
    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $options = new ServerOptions(securityMiddlewareMap: $middlewareMap);
    $collector = new OperationCollector($options, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);
    $code = (new RouteGenerator($options))->generate($descriptors)->code;

    return [$code, $collector->warnings()];
}

/**
 * @return array<string, mixed>
 */
function securedPetstore(): array
{
    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Secured', 'version' => '1.0.0'],
        'security' => [['bearerAuth' => []]],
        'paths' => [
            '/pets' => [
                // Inherits the global bearerAuth requirement.
                'get' => [
                    'tags' => ['Pet'],
                    'operationId' => 'listPets',
                    'responses' => ['200' => ['description' => 'OK']],
                ],
                // Overrides it with apiKey.
                'post' => [
                    'tags' => ['Pet'],
                    'operationId' => 'createPet',
                    'security' => [['apiKey' => []]],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
            '/health' => [
                // Explicitly public: security: [].
                'get' => [
                    'tags' => ['Meta'],
                    'operationId' => 'getHealth',
                    'security' => [],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                'apiKey' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
            ],
        ],
    ];
}

it('puts the mapped middleware on inherited-global and overriding operations, and none on an explicit security: []', function () {
    [$code, $warnings] = securedRoutesRun(securedPetstore(), [
        'bearerAuth' => ['auth:sanctum'],
        'apiKey' => ['auth.apikey'],
    ]);

    expect($code)->toContain("Route::get('/pets', [PetController::class, 'index'])->name('index_2')->middleware(['auth:sanctum']);")
        // The 201 status middleware (issue #64) folds into the same array.
        ->and($code)->toContain("Route::post('/pets', [PetController::class, 'store'])->name('store')->middleware(['auth.apikey', RespondsWithStatus::class.':201']);")
        // security: [] means public: no middleware at all.
        ->and($code)->toContain("Route::get('/health', [MetaController::class, 'index'])->name('index');")
        ->and($warnings)->toBe([])
        ->and(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

it('applies all mapped middleware of an AND requirement to one route, deduplicated', function () {
    $document = securedPetstore();
    $document['paths']['/pets']['get']['security'] = [['bearerAuth' => [], 'apiKey' => []]];

    [$code] = securedRoutesRun($document, [
        'bearerAuth' => ['auth:sanctum', 'throttle:60,1'],
        'apiKey' => ['auth.apikey', 'throttle:60,1'],
    ]);

    expect($code)->toContain("'index'])->name('index_2')->middleware(['auth:sanctum', 'throttle:60,1', 'auth.apikey']);");
});

it('emits no middleware and no warning when nothing is mapped to a public spec, byte-identical to before', function () {
    $document = securedPetstore();
    unset($document['security'], $document['paths']['/pets']['get']['security'], $document['paths']['/pets']['post']['security'], $document['paths']['/health']['get']['security'], $document['components']['securitySchemes']);

    [$code, $warnings] = securedRoutesRun($document);

    expect($code)->not->toContain("->middleware(['")
        ->and($code)->toContain("->middleware(RespondsWithStatus::class.':201');")
        ->and($warnings)->toBe([]);
});

it('warns about every required-but-unmapped scheme and leaves the routes open when no map is configured', function () {
    [$code, $warnings] = securedRoutesRun(securedPetstore());

    expect($code)->not->toContain("->middleware(['")
        ->and($warnings)->toContain(
            'Security scheme "bearerAuth" is required by the spec but has no entry in security.middleware_map; the generated routes requiring it carry no auth middleware. Map it to one or more middleware names, or to an empty list to acknowledge it is handled elsewhere.',
        )
        ->and($warnings)->toContain(
            'Security scheme "apiKey" is required by the spec but has no entry in security.middleware_map; the generated routes requiring it carry no auth middleware. Map it to one or more middleware names, or to an empty list to acknowledge it is handled elsewhere.',
        );
});

it('enforces the first OR alternative on the route and warns per operation about the ignored ones', function () {
    $document = securedPetstore();
    $document['paths']['/pets']['get']['security'] = [['bearerAuth' => []], ['apiKey' => []]];

    [$code, $warnings] = securedRoutesRun($document, [
        'bearerAuth' => ['auth:sanctum'],
        'apiKey' => ['auth.apikey'],
    ]);

    expect($code)->toContain("'index'])->name('index_2')->middleware(['auth:sanctum']);")
        ->and($warnings)->toContain(
            'Operation GET /pets: security declares 2 alternative requirements (OR), which middleware cannot express; only the first alternative (bearerAuth) is enforced, ignored: (apiKey).',
        );
});

it('warns about a mapped scheme name the spec does not declare', function () {
    [, $warnings] = securedRoutesRun(securedPetstore(), [
        'bearerAuth' => ['auth:sanctum'],
        'apiKey' => ['auth.apikey'],
        'bearerAuht' => ['auth:sanctum'],
    ]);

    expect($warnings)->toContain(
        'security.middleware_map maps scheme "bearerAuht", but the spec declares no security scheme with that name in components.securitySchemes; check the map for a typo.',
    );
});

it('escapes quotes and backslashes in mapped middleware names', function () {
    [$code] = securedRoutesRun(securedPetstore(), [
        'bearerAuth' => ["o'middleware"],
        'apiKey' => ['auth.apikey'],
    ]);

    expect($code)->toContain("->middleware(['o\\'middleware']);")
        ->and(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

it('combines security middleware on the routes with a configured route group (#71)', function () {
    $spec = (new OpenApiReader)->read(securedPetstore());
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $options = new ServerOptions(
        routeMiddleware: ['api'],
        routePrefix: 'v1',
        securityMiddlewareMap: ['bearerAuth' => ['auth:sanctum'], 'apiKey' => ['auth.apikey']],
    );
    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($spec);
    $code = (new RouteGenerator($options))->generate($descriptors)->code;

    expect($code)->toContain("Route::middleware(['api'])->prefix('v1')->group(function (): void {")
        ->and($code)->toContain("    Route::get('/pets', [PetController::class, 'index'])->name('index_2')->middleware(['auth:sanctum']);")
        ->and(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

it('is deterministic: the same secured spec emits byte-identical routes twice', function () {
    $map = ['bearerAuth' => ['auth:sanctum'], 'apiKey' => ['auth.apikey']];
    [$first] = securedRoutesRun(securedPetstore(), $map);
    [$second] = securedRoutesRun(securedPetstore(), $map);

    expect($first)->toBe($second);
});
