<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\PathPrefixFilter;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Unit tests for the path-prefix exclusion filter (issue #96): drops every
 * path starting with a configured prefix before the closure and the operation
 * collector run. The typed document graph is read-only (issue #104), so the
 * filter returns a new document plus the unmatched prefixes. Feature tests
 * prove the planner wires it through to controllers, routes, and the drift
 * gate.
 */

/**
 * @param  list<string>  $paths
 */
function filterDocument(array $paths): OpenApiDocument
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

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return $spec;
}

/**
 * @return list<string>
 */
function remainingPaths(OpenApiDocument $document): array
{
    return array_map(strval(...), array_keys($document->paths));
}

it('removes every path starting with the prefix', function () {
    $document = filterDocument(['/pets', '/api/v1/swagger/pets', '/api/v1/swagger/orders']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, ['/api/v1/swagger']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/pets']);
});

it('applies multiple prefixes in one pass', function () {
    $document = filterDocument(['/pets', '/internal/metrics', '/api/v1/swagger/pets']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, ['/api/v1/swagger', '/internal']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/pets']);
});

it('matches as a literal string prefix, not per path segment', function () {
    // "/pet" is a prefix of "/pets" as a string; the documented semantics are
    // a plain str_starts_with, so both fall.
    $document = filterDocument(['/pets', '/pet/{id}', '/orders']);

    [$document] = (new PathPrefixFilter)->apply($document, ['/pet']);

    expect(remainingPaths($document))->toBe(['/orders']);
});

it('matches case-sensitively', function () {
    $document = filterDocument(['/Internal/metrics']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, ['/internal']);

    expect($unmatched)->toBe(['/internal'])
        ->and(remainingPaths($document))->toBe(['/Internal/metrics']);
});

it('reports the prefixes that matched nothing, in input order', function () {
    $document = filterDocument(['/pets']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, ['/ghost', '/pets', '/missing']);

    expect($unmatched)->toBe(['/ghost', '/missing'])
        ->and(remainingPaths($document))->toBe([]);
});

it('leaves the document untouched with no prefixes', function () {
    $document = filterDocument(['/pets', '/orders']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, []);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/pets', '/orders']);
});

it('trims entries and drops empty and duplicate prefixes', function () {
    $document = filterDocument(['/pets', '/orders']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, ['  /pets  ', '', '   ', '/pets']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe(['/orders']);
});

it('can exclude every path, leaving an empty path set', function () {
    $document = filterDocument(['/pets', '/pets/{id}']);

    [$document, $unmatched] = (new PathPrefixFilter)->apply($document, ['/']);

    expect($unmatched)->toBe([])
        ->and(remainingPaths($document))->toBe([]);
});
