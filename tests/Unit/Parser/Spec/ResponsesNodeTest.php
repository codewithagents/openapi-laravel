<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\MediaTypeNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponseNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponsesNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * #104 T1: ResponsesNode keeps status code keys as written in the spec, with
 * one PHP reality acknowledged: purely numeric keys are canonicalized to int
 * by the engine, so the string-speaking accessors (has, get, statusCodes) are
 * the stable surface for the success-status pick (issue #64).
 */
it('answers string status code lookups across range keys and default', function () {
    $responses = new ResponsesNode([
        '200' => new ResponseNode(description: 'OK'),
        '4XX' => new ResponseNode(description: 'client error'),
        'default' => new ReferenceNode('#/components/responses/Error'),
    ]);

    expect($responses->statusCodes())->toBe(['200', '4XX', 'default'])
        ->and($responses->has('200'))->toBeTrue()
        ->and($responses->has('204'))->toBeFalse()
        ->and($responses->get('4XX'))->toBeInstanceOf(ResponseNode::class)
        ->and($responses->get('default'))->toBeInstanceOf(ReferenceNode::class)
        ->and($responses->get('500'))->toBeNull();
});

it('exposes typed media type content on a response', function () {
    $response = new ResponseNode(
        description: 'OK',
        content: ['application/json' => new MediaTypeNode(schema: new SchemaNode(type: 'object'))],
    );

    expect($response->content)->toHaveKey('application/json')
        ->and($response->content['application/json']->schema)->toBeInstanceOf(SchemaNode::class);
});

it('types the 3.2 itemSchema stub on a media type from day one', function () {
    $mediaType = new MediaTypeNode(
        schema: new SchemaNode(type: 'array'),
        itemSchema: new SchemaNode(type: 'object'),
    );

    expect($mediaType->itemSchema)->toBeInstanceOf(SchemaNode::class)
        ->and((new MediaTypeNode)->itemSchema)->toBeNull();
});
