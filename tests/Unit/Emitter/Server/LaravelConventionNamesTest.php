<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\Server\LaravelConventionNames;

/*
 * The raw conventional-name mapping (issue #94): one candidate (or null) per
 * (HTTP method, path) pair. The per-controller ambiguity rule is tested at the
 * OperationCollector level; this covers the mapping table and the
 * collection-versus-item path classification exhaustively.
 */

it('maps every HTTP method against collection and item paths per the convention table', function (string $method, string $path, ?string $expected) {
    expect(LaravelConventionNames::candidate($method, $path))->toBe($expected);
})->with([
    // The five conventional mappings.
    'GET collection -> index' => ['get', '/pets', 'index'],
    'POST collection -> store' => ['post', '/pets', 'store'],
    'GET item -> show' => ['get', '/pets/{petId}', 'show'],
    'PUT item -> update' => ['put', '/pets/{petId}', 'update'],
    'PATCH item -> update' => ['patch', '/pets/{petId}', 'update'],
    'DELETE item -> destroy' => ['delete', '/pets/{petId}', 'destroy'],

    // Non-CRUD pairs always fall back (null).
    'POST item is not conventional' => ['post', '/pets/{petId}', null],
    'PUT collection is not conventional' => ['put', '/pets', null],
    'PATCH collection is not conventional' => ['patch', '/pets', null],
    'DELETE collection is not conventional' => ['delete', '/pets', null],
    'HEAD collection is not conventional' => ['head', '/pets', null],
    'HEAD item is not conventional' => ['head', '/pets/{petId}', null],
    'OPTIONS collection is not conventional' => ['options', '/pets', null],
    'OPTIONS item is not conventional' => ['options', '/pets/{petId}', null],
    'TRACE collection is not conventional' => ['trace', '/pets', null],
    'TRACE item is not conventional' => ['trace', '/pets/{petId}', null],
]);

it('classifies a path by its LAST non-empty segment only', function (string $method, string $path, ?string $expected) {
    expect(LaravelConventionNames::candidate($method, $path))->toBe($expected);
})->with([
    // A parameter in the middle does not make an item path.
    'nested collection under an item' => ['get', '/users/{userId}/pets', 'index'],
    'nested item under an item' => ['get', '/users/{userId}/pets/{petId}', 'show'],
    'POST on a nested collection' => ['post', '/users/{userId}/pets', 'store'],
    'DELETE on a nested item' => ['delete', '/users/{userId}/pets/{petId}', 'destroy'],

    // Edge shapes. The root has no segments; a trailing slash contributes no
    // segment; malformed braces stay literals; `{}` has no parameter name.
    'root path is a collection' => ['get', '/', 'index'],
    'trailing slash classifies by the literal before it' => ['get', '/pets/', 'index'],
    'trailing slash after a parameter still ends with the parameter' => ['get', '/pets/{petId}/', 'show'],
    'unclosed brace segment is a literal' => ['get', '/pets/{petId', 'index'],
    'unopened brace segment is a literal' => ['get', '/pets/petId}', 'index'],
    'empty braces are not a parameter' => ['get', '/pets/{}', 'index'],
]);
