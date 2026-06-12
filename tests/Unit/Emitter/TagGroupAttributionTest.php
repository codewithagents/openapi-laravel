<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\SchemaClosure;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Unit tests for the schema-to-tag attribution of the opt-in grouped data
 * layout (issue #93): SchemaClosure::attributeByTag() decides which component
 * schemas are solely owned by one tag group (and move into its subdirectory)
 * and which stay at the flat root. These assert the attribution map directly;
 * the emitter and feature tests prove the layout that is built from it.
 *
 * @param  array<string, mixed>  $schemas
 * @param  array<string, mixed>  $paths
 */
function attributionDocument(array $schemas, array $paths): OpenApiDocument
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths === [] ? new stdClass : $paths,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return $spec;
}

/**
 * A GET operation responding with the named schema, carrying the given tags.
 *
 * @param  list<string>  $tags
 * @return array<string, mixed>
 */
function operationReturning(string $schema, array $tags, string $operationId): array
{
    return [
        'get' => [
            'operationId' => $operationId,
            'tags' => $tags,
            'responses' => [
                '200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
                ],
            ],
        ],
    ];
}

it('attributes a schema referenced by operations of exactly one tag to that tag group', function () {
    $document = attributionDocument(
        ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
        ['/pets' => operationReturning('Pet', ['pet'], 'listPets')],
    );

    expect((new SchemaClosure)->attributeByTag($document))->toBe(['Pet' => 'Pet']);
});

it('keeps a schema referenced by several tag groups at the flat root', function () {
    $document = attributionDocument(
        ['Shared' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        [
            '/pets' => operationReturning('Shared', ['pet'], 'a'),
            '/stores' => operationReturning('Shared', ['store'], 'b'),
        ],
    );

    expect((new SchemaClosure)->attributeByTag($document))->toBe([]);
});

it('keeps an unreferenced schema at the flat root', function () {
    $document = attributionDocument(
        [
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Orphan' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
        ],
        ['/pets' => operationReturning('Pet', ['pet'], 'listPets')],
    );

    expect((new SchemaClosure)->attributeByTag($document))->toBe(['Pet' => 'Pet']);
});

it('attributes the transitive closure of a sole tag, and roots a dependency another tag also reaches', function () {
    $document = attributionDocument(
        [
            'Order' => [
                'type' => 'object',
                'properties' => [
                    'item' => ['$ref' => '#/components/schemas/Item'],
                    'address' => ['$ref' => '#/components/schemas/Address'],
                ],
            ],
            'Item' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]],
            'Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            'Customer' => [
                'type' => 'object',
                'properties' => ['address' => ['$ref' => '#/components/schemas/Address']],
            ],
        ],
        [
            '/orders' => operationReturning('Order', ['order'], 'listOrders'),
            '/customers' => operationReturning('Customer', ['customer'], 'listCustomers'),
        ],
    );

    // Item is reachable only through order's closure; Address is reachable
    // from both tags (transitively), so it stays at the root.
    expect((new SchemaClosure)->attributeByTag($document))->toBe([
        'Customer' => 'Customer',
        'Item' => 'Order',
        'Order' => 'Order',
    ]);
});

it('uses only the FIRST tag of a multi-tag operation, matching the controller grouping', function () {
    $document = attributionDocument(
        ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
        ['/pets' => operationReturning('Pet', ['pet', 'animals'], 'listPets')],
    );

    // The second tag never claims the schema: the operation belongs to one
    // controller (PetController), so its schema belongs to one group.
    expect((new SchemaClosure)->attributeByTag($document))->toBe(['Pet' => 'Pet']);
});

it('groups schemas of untagged operations under the Untagged pseudo-group', function () {
    $document = attributionDocument(
        ['Health' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]],
        [
            '/health' => [
                'get' => [
                    'operationId' => 'health',
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Health']]],
                        ],
                    ],
                ],
            ],
        ],
    );

    expect((new SchemaClosure)->attributeByTag($document))->toBe(['Health' => 'Untagged']);
});

it('merges tags that normalize to the same StudlyCaps group, like controllers do', function () {
    $document = attributionDocument(
        ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
        [
            '/pets' => operationReturning('Pet', ['pet store'], 'a'),
            '/pets/{id}' => operationReturning('Pet', ['PetStore'], 'b'),
        ],
    );

    // 'pet store' and 'PetStore' share one controller, so they share one
    // group: the schema is sole-owned, not multi-tag.
    expect((new SchemaClosure)->attributeByTag($document))->toBe(['Pet' => 'PetStore']);
});

it('never lets a tag claim the reserved Support group', function () {
    $document = attributionDocument(
        ['Ticket' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ['/tickets' => operationReturning('Ticket', ['support'], 'listTickets')],
    );

    // Support/ belongs to the inlined runtime classes (issue #40); a tag
    // normalizing to 'Support' keeps its schemas at the flat root.
    expect((new SchemaClosure)->attributeByTag($document))->toBe([]);
});

it('still counts a Support-tag reference for multi-group detection', function () {
    $document = attributionDocument(
        ['Shared' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        [
            '/tickets' => operationReturning('Shared', ['support'], 'a'),
            '/pets' => operationReturning('Shared', ['pet'], 'b'),
        ],
    );

    // The pet tag is not the sole owner: the support operations reach the
    // schema too, so it stays at the root instead of moving into Pet/.
    expect((new SchemaClosure)->attributeByTag($document))->toBe([]);
});

it('pulls a discriminated union base and all variants into the sole owning group', function () {
    $document = attributionDocument(
        [
            'Animal' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['$ref' => '#/components/schemas/Dog'],
                ],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'barks' => ['type' => 'boolean']]],
        ],
        ['/animals' => operationReturning('Animal', ['animal'], 'listAnimals')],
    );

    expect((new SchemaClosure)->attributeByTag($document))->toBe([
        'Animal' => 'Animal',
        'Cat' => 'Animal',
        'Dog' => 'Animal',
    ]);
});

it('is deterministic regardless of path declaration order', function () {
    $schemas = [
        'A' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
        'B' => ['type' => 'object', 'properties' => ['y' => ['type' => 'string']]],
    ];

    $forward = attributionDocument($schemas, [
        '/a' => operationReturning('A', ['alpha'], 'a'),
        '/b' => operationReturning('B', ['beta'], 'b'),
    ]);
    $reversed = attributionDocument($schemas, [
        '/b' => operationReturning('B', ['beta'], 'b'),
        '/a' => operationReturning('A', ['alpha'], 'a'),
    ]);

    $closure = new SchemaClosure;

    expect($closure->attributeByTag($forward))->toBe($closure->attributeByTag($reversed))
        ->and($closure->attributeByTag($forward))->toBe(['A' => 'Alpha', 'B' => 'Beta']);
});
