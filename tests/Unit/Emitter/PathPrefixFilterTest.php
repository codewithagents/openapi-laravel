<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\PathPrefixFilter;

/**
 * Unit tests for the path-prefix exclusion filter (issue #96): the focused
 * document mutation that drops every path starting with a configured prefix
 * before the closure and the operation collector run. Feature tests prove the
 * planner wires it through to controllers, routes, and the drift gate.
 */

/**
 * @param  list<string>  $paths
 */
function filterDocument(array $paths): OpenApi
{
    $pathItems = [];
    foreach ($paths as $index => $path) {
        $pathItems[$path] = [
            'get' => [
                'operationId' => 'op'.$index,
                'responses' => ['200' => ['description' => 'ok']],
            ],
        ];
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Filter', 'version' => '1.0.0'],
        'paths' => $pathItems === [] ? new stdClass : $pathItems,
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return $spec;
}

/**
 * @return list<string>
 */
function remainingPaths(OpenApi $document): array
{
    $paths = $document->paths;

    return $paths === null ? [] : array_map(strval(...), array_keys($paths->getPaths()));
}

it('removes every path starting with the prefix', function () {
    $document = filterDocument(['/pets', '/api/v1/swagger/pets', '/api/v1/swagger/orders']);

    $unmatched = (new PathPrefixFilter)->apply($document, ['/api/v1/swagger']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/pets']);
});

it('applies multiple prefixes in one pass', function () {
    $document = filterDocument(['/pets', '/internal/metrics', '/api/v1/swagger/pets']);

    $unmatched = (new PathPrefixFilter)->apply($document, ['/api/v1/swagger', '/internal']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/pets']);
});

it('matches as a literal string prefix, not per path segment', function () {
    // "/pet" is a prefix of "/pets" as a string; the documented semantics are
    // a plain str_starts_with, so both fall.
    $document = filterDocument(['/pets', '/pet/{id}', '/orders']);

    (new PathPrefixFilter)->apply($document, ['/pet']);

    expect(remainingPaths($document))->toBe(['/orders']);
});

it('matches case-sensitively', function () {
    $document = filterDocument(['/Internal/metrics']);

    $unmatched = (new PathPrefixFilter)->apply($document, ['/internal']);

    expect($unmatched)->toBe(['/internal'])
        ->and(remainingPaths($document))->toBe(['/Internal/metrics']);
});

it('reports the prefixes that matched nothing, in input order', function () {
    $document = filterDocument(['/pets']);

    $unmatched = (new PathPrefixFilter)->apply($document, ['/ghost', '/pets', '/missing']);

    expect($unmatched)->toBe(['/ghost', '/missing'])
        ->and(remainingPaths($document))->toBe([]);
});

it('leaves the document untouched with no prefixes', function () {
    $document = filterDocument(['/pets', '/orders']);

    $unmatched = (new PathPrefixFilter)->apply($document, []);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/pets', '/orders']);
});

it('trims entries and drops empty and duplicate prefixes', function () {
    $document = filterDocument(['/pets', '/orders']);

    $unmatched = (new PathPrefixFilter)->apply($document, ['  /pets  ', '', '   ', '/pets']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/orders']);
});

it('can exclude every path, leaving an empty path set', function () {
    $document = filterDocument(['/pets', '/pets/{id}']);

    $unmatched = (new PathPrefixFilter)->apply($document, ['/']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe([]);
});
