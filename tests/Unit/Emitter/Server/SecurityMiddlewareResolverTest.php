<?php

declare(strict_types=1);

use cebe\openapi\spec\SecurityRequirement;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\SecurityMiddlewareResolver;

/**
 * Issue #77: the pure resolution logic from spec security declarations to
 * route middleware. The emission side (the routes file) is covered by
 * SecurityMiddlewareTest; this file pins the semantics: operation override,
 * the explicit-empty public case, AND within one requirement object, the
 * documented first-alternative-wins OR behavior, and every warning.
 */

/**
 * @param  list<array<string, list<string>>>  $requirements
 * @return list<SecurityRequirement>
 */
function securityRequirements(array $requirements): array
{
    return array_map(
        static fn (array $requirement): SecurityRequirement => new SecurityRequirement($requirement),
        $requirements,
    );
}

it('inherits the global security when the operation declares none', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['bearerAuth' => ['auth:sanctum']],
        securityRequirements([['bearerAuth' => []]]),
    );

    expect($resolver->middlewareFor('GET /pets', null))->toBe(['auth:sanctum'])
        ->and($resolver->warnings())->toBe([]);
});

it('lets operation-level security override the global one entirely', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['bearerAuth' => ['auth:sanctum'], 'apiKey' => ['auth.apikey']],
        securityRequirements([['bearerAuth' => []]]),
    );

    $middleware = $resolver->middlewareFor('GET /pets', securityRequirements([['apiKey' => []]]));

    expect($middleware)->toBe(['auth.apikey']);
});

it('treats an explicit empty security array as public, even with global security', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['bearerAuth' => ['auth:sanctum']],
        securityRequirements([['bearerAuth' => []]]),
    );

    expect($resolver->middlewareFor('GET /health', []))->toBe([])
        ->and($resolver->warnings())->toBe([]);
});

it('resolves to nothing when neither the operation nor the document declares security', function () {
    $resolver = new SecurityMiddlewareResolver(['bearerAuth' => ['auth:sanctum']], []);

    expect($resolver->middlewareFor('GET /pets', null))->toBe([])
        ->and($resolver->warnings())->toBe([]);
});

it('applies every mapped scheme of one requirement object (AND), in spec order, deduplicated', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['apiKey' => ['auth.apikey', 'throttle:60,1'], 'bearerAuth' => ['auth:sanctum', 'throttle:60,1']],
        [],
    );

    $middleware = $resolver->middlewareFor('GET /pets', securityRequirements([
        ['apiKey' => [], 'bearerAuth' => []],
    ]));

    expect($middleware)->toBe(['auth.apikey', 'throttle:60,1', 'auth:sanctum'])
        ->and($resolver->warnings())->toBe([]);
});

it('enforces only the first requirement object (OR) and warns once per operation about the rest', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['bearerAuth' => ['auth:sanctum'], 'apiKey' => ['auth.apikey']],
        [],
    );

    $operationSecurity = securityRequirements([
        ['bearerAuth' => []],
        ['apiKey' => []],
        ['oauth' => ['read'], 'basicAuth' => []],
    ]);

    expect($resolver->middlewareFor('GET /pets', $operationSecurity))->toBe(['auth:sanctum'])
        ->and(array_keys($resolver->warnings()))->toBe([
            'Operation GET /pets: security declares 3 alternative requirements (OR), which middleware cannot express; only the first alternative (bearerAuth) is enforced, ignored: (apiKey), (oauth + basicAuth).',
        ]);

    // The same operation resolved again does not duplicate the warning.
    $resolver->middlewareFor('GET /pets', $operationSecurity);
    expect($resolver->warnings())->toHaveCount(1);
});

it('treats an empty first requirement object as anonymous access: nothing is enforced', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['bearerAuth' => ['auth:sanctum']],
        [],
    );

    $middleware = $resolver->middlewareFor('GET /pets', securityRequirements([
        [],
        ['bearerAuth' => []],
    ]));

    expect($middleware)->toBe([])
        ->and(array_keys($resolver->warnings()))->toBe([
            'Operation GET /pets: security declares 2 alternative requirements (OR), which middleware cannot express; only the first alternative (anonymous access) is enforced, ignored: (bearerAuth).',
        ]);
});

it('warns once per scheme the spec requires but the map does not name, and keeps the mapped ones', function () {
    $resolver = new SecurityMiddlewareResolver(['bearerAuth' => ['auth:sanctum']], []);

    $middleware = $resolver->middlewareFor('GET /pets', securityRequirements([
        ['bearerAuth' => [], 'apiKey' => []],
    ]));

    expect($middleware)->toBe(['auth:sanctum'])
        ->and(array_keys($resolver->warnings()))->toBe([
            'Security scheme "apiKey" is required by the spec but has no entry in security.middleware_map; the generated routes requiring it carry no auth middleware. Map it to one or more middleware names, or to an empty list to acknowledge it is handled elsewhere.',
        ]);

    // A second operation requiring the same scheme does not duplicate it.
    $resolver->middlewareFor('DELETE /pets/{petId}', securityRequirements([['apiKey' => []]]));
    expect($resolver->warnings())->toHaveCount(1);
});

it('treats a scheme mapped to an empty list as acknowledged: no middleware and no warning', function () {
    $resolver = new SecurityMiddlewareResolver(['apiKey' => []], []);

    expect($resolver->middlewareFor('GET /pets', securityRequirements([['apiKey' => []]])))->toBe([])
        ->and($resolver->warnings())->toBe([]);
});

it('warns for a mapped scheme name the spec never declares in components.securitySchemes', function () {
    $resolver = new SecurityMiddlewareResolver(
        ['bearerAuth' => ['auth:sanctum'], 'bearerAuht' => ['auth:sanctum']],
        [],
    );

    $resolver->warnUndeclaredMappings(['bearerAuth', 'apiKey']);

    expect(array_keys($resolver->warnings()))->toBe([
        'security.middleware_map maps scheme "bearerAuht", but the spec declares no security scheme with that name in components.securitySchemes; check the map for a typo.',
    ]);
});
