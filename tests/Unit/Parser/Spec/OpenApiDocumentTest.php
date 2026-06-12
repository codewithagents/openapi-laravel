<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\ComponentsNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\InfoNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\MediaTypeNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OperationNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\PathItemNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\RequestBodyNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponseNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponsesNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SecurityRequirementNode;

/**
 * #104 T1: the full value-object graph composes the way the future
 * OpenApiReader will hydrate it: a petstore-shaped document built by hand,
 * exercising every node type end to end, with the read-only and presence
 * guarantees holding at the root.
 */
function petstoreDocument(): OpenApiDocument
{
    return new OpenApiDocument(
        openapi: '3.1.0',
        info: new InfoNode(title: 'Petstore', version: '1.0.0', summary: 'A sample API'),
        paths: [
            '/pets' => new PathItemNode(
                get: new OperationNode(
                    operationId: 'listPets',
                    tags: ['pets'],
                    parameters: [
                        new ParameterNode(name: 'limit', in: 'query', schema: new SchemaNode(type: 'integer')),
                        new ReferenceNode('#/components/parameters/PageCursor'),
                    ],
                    responses: new ResponsesNode([
                        '200' => new ResponseNode(
                            description: 'OK',
                            content: ['application/json' => new MediaTypeNode(
                                schema: new SchemaNode(type: 'array', items: new ReferenceNode('#/components/schemas/Pet')),
                            )],
                        ),
                    ]),
                ),
                post: new OperationNode(
                    operationId: 'createPet',
                    tags: ['pets'],
                    requestBody: new RequestBodyNode(
                        content: ['application/json' => new MediaTypeNode(schema: new ReferenceNode('#/components/schemas/Pet'))],
                        required: true,
                    ),
                    responses: new ResponsesNode(['201' => new ResponseNode(description: 'Created')]),
                    security: [new SecurityRequirementNode(['api_key' => []])],
                ),
            ),
        ],
        components: new ComponentsNode(
            schemas: [
                'Pet' => new SchemaNode(
                    type: 'object',
                    required: ['id', 'name'],
                    properties: [
                        'id' => new SchemaNode(type: 'integer', format: 'int64'),
                        'name' => new SchemaNode(type: 'string'),
                        'tag' => new SchemaNode(type: 'string', default: 'none', hasDefault: true),
                    ],
                    additionalProperties: false,
                    hasAdditionalProperties: true,
                ),
            ],
            securitySchemes: ['api_key' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header']],
        ),
        security: [new SecurityRequirementNode(['api_key' => []])],
        warnings: ['OpenAPI 3.2 construct encountered: additionalOperations (best effort)'],
    );
}

it('composes the full typed graph end to end', function () {
    $document = petstoreDocument();

    $get = $document->paths['/pets']->get;
    $post = $document->paths['/pets']->post;

    expect($document->openapi)->toBe('3.1.0')
        ->and($document->info->title)->toBe('Petstore')
        ->and($document->info->summary)->toBe('A sample API')
        ->and($get?->operationId)->toBe('listPets')
        ->and($get->parameters[0])->toBeInstanceOf(ParameterNode::class)
        ->and($get->parameters[1])->toBeInstanceOf(ReferenceNode::class)
        ->and($get->responses?->get('200'))->toBeInstanceOf(ResponseNode::class)
        ->and($post?->requestBody)->toBeInstanceOf(RequestBodyNode::class)
        ->and($post->requestBody->required)->toBeTrue()
        ->and($document->components?->schemas['Pet'])->toBeInstanceOf(SchemaNode::class)
        ->and($document->components->securitySchemes['api_key']['type'])->toBe('apiKey')
        ->and($document->warnings)->toHaveCount(1);
});

it('reaches the nested array items reference through typed properties only', function () {
    $document = petstoreDocument();

    $schema = $document->paths['/pets']->get?->responses?->get('200');
    assert($schema instanceof ResponseNode);
    $items = $schema->content['application/json']->schema;
    assert($items instanceof SchemaNode);

    expect($items->items)->toBeInstanceOf(ReferenceNode::class)
        ->and($items->items->pointer())->toBe('#/components/schemas/Pet');
});

it('defaults the optional root fields to absent', function () {
    $minimal = new OpenApiDocument(openapi: '3.0.3', info: new InfoNode(title: 'T', version: '1'));

    expect($minimal->paths)->toBe([])
        ->and($minimal->components)->toBeNull()
        ->and($minimal->webhooks)->toBe([])
        ->and($minimal->security)->toBeNull()
        ->and($minimal->tags)->toBeNull()
        ->and($minimal->servers)->toBeNull()
        ->and($minimal->warnings)->toBe([])
        ->and($minimal->extensions)->toBe([]);
});

it('distinguishes absent global security from the explicit empty list', function () {
    $absent = new OpenApiDocument(openapi: '3.0.3', info: new InfoNode(title: 'T', version: '1'));
    $explicitEmpty = new OpenApiDocument(openapi: '3.0.3', info: new InfoNode(title: 'T', version: '1'), security: []);

    expect($absent->security)->toBeNull()
        ->and($explicitEmpty->security)->toBe([]);
});

it('is read-only at the root: writing a property throws', function () {
    $document = petstoreDocument();

    expect(fn () => $document->openapi = '3.0.0')->toThrow(Error::class, 'readonly');
});
