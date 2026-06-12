<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OperationNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\PathItemNode;

/**
 * #104 T1: PathItemNode types the eight fixed HTTP methods plus the OpenAPI
 * 3.2 fixed fields (the `query` method and `additionalOperations`) from day
 * one. operations() is the cebe getOperations() replacement and must return
 * only declared methods, keyed lowercase, in fixed-method-then-additional
 * order.
 */
it('returns only the declared operations keyed by method', function () {
    $pathItem = new PathItemNode(
        get: new OperationNode(operationId: 'listPets'),
        post: new OperationNode(operationId: 'createPet'),
    );

    $operations = $pathItem->operations();

    expect(array_keys($operations))->toBe(['get', 'post'])
        ->and($operations['get']->operationId)->toBe('listPets')
        ->and($operations['post']->operationId)->toBe('createPet');
});

it('returns an empty map for a path item without operations', function () {
    expect((new PathItemNode(summary: 'placeholder'))->operations())->toBe([]);
});

it('includes the 3.2 query method and additionalOperations entries', function () {
    $pathItem = new PathItemNode(
        get: new OperationNode(operationId: 'listPets'),
        query: new OperationNode(operationId: 'queryPets'),
        additionalOperations: ['COPY' => new OperationNode(operationId: 'copyPets')],
    );

    $operations = $pathItem->operations();

    expect(array_keys($operations))->toBe(['get', 'query', 'copy'])
        ->and($operations['query']->operationId)->toBe('queryPets')
        ->and($operations['copy']->operationId)->toBe('copyPets');
});

it('keeps an unresolved path-level $ref as its raw pointer string', function () {
    $pathItem = new PathItemNode(ref: '#/components/pathItems/Shared');

    expect($pathItem->ref)->toBe('#/components/pathItems/Shared')
        ->and($pathItem->operations())->toBe([]);
});
