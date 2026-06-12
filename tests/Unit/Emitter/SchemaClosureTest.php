<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ResolvedClosure;
use CodeWithAgents\OpenApiLaravel\Emitter\SchemaClosure;
use CodeWithAgents\OpenApiLaravel\Emitter\SubsetSelection;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Unit tests for the transitive dependency closure (issue #44): the focused
 * graph walk that, given a tag/schema selection, computes the self-consistent
 * set of component schemas (and kept operations) the generator must emit so no
 * `$ref` dangles. These assert the closure directly; feature tests prove the
 * generator wires it through.
 *
 * @param  array<string, mixed>  $schemas
 * @param  array<string, mixed>  $paths
 */
function closureDocument(array $schemas, array $paths = []): OpenApiDocument
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
 * @param  list<string>  $schemas
 * @param  list<string>  $tags
 */
function resolveClosure(OpenApiDocument $document, array $schemas = [], array $tags = []): ResolvedClosure
{
    return (new SchemaClosure)->resolve($document, SubsetSelection::of($tags, $schemas));
}

it('includes a directly referenced property schema', function () {
    $document = closureDocument([
        'Order' => [
            'type' => 'object',
            'properties' => ['customer' => ['$ref' => '#/components/schemas/Customer']],
        ],
        'Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Unrelated' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Order']);

    expect($closure->schemas)->toBe(['Customer', 'Order'])
        ->and($closure->hasUnknown())->toBeFalse();
});

it('follows a nested array items ref at every depth', function () {
    $document = closureDocument([
        'Matrix' => [
            'type' => 'object',
            'properties' => [
                'rows' => [
                    'type' => 'array',
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Cell']],
                ],
            ],
        ],
        'Cell' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Matrix']);

    expect($closure->schemas)->toBe(['Cell', 'Matrix']);
});

it('follows an additionalProperties value ref', function () {
    $document = closureDocument([
        'Bag' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Item']],
        'Item' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Bag']);

    expect($closure->schemas)->toBe(['Bag', 'Item']);
});

it('follows allOf, oneOf, and anyOf members', function () {
    $document = closureDocument([
        'Composite' => [
            'allOf' => [['$ref' => '#/components/schemas/A']],
            'properties' => [
                'one' => ['oneOf' => [['$ref' => '#/components/schemas/B']]],
                'any' => ['anyOf' => [['$ref' => '#/components/schemas/C']]],
            ],
        ],
        'A' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        'B' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        'C' => ['type' => 'object', 'properties' => ['c' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Composite']);

    expect($closure->schemas)->toBe(['A', 'B', 'C', 'Composite']);
});

it('pulls in every variant when a discriminated base is selected', function () {
    $document = closureDocument([
        'Pet' => [
            'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'dog' => 'Dog']],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Pet']);

    expect($closure->schemas)->toBe(['Cat', 'Dog', 'Pet']);
});

it('pulls in the base and its sibling variants when a single variant is selected', function () {
    $document = closureDocument([
        'Pet' => [
            'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'dog' => 'Dog']],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
    ]);

    // Selecting only the Cat variant must still drag in the base (it `extends`
    // it) and the Dog sibling (the base's morph() arms reference it), or the
    // generated union would not resolve.
    $closure = resolveClosure($document, schemas: ['Cat']);

    expect($closure->schemas)->toBe(['Cat', 'Dog', 'Pet']);
});

it('pulls in a mapping-only discriminator target not listed in oneOf', function () {
    $document = closureDocument([
        'Pet' => [
            'oneOf' => [['$ref' => '#/components/schemas/Cat']],
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'dog' => '#/components/schemas/Dog']],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Pet']);

    expect($closure->schemas)->toContain('Dog');
});

it('follows a bare-name discriminator mapping target', function () {
    $document = closureDocument([
        'Pet' => [
            'oneOf' => [['$ref' => '#/components/schemas/Cat']],
            // Dog is named ONLY through a bare-name mapping value (not a $ref
            // pointer and not a oneOf member), so only the mapping-target branch
            // of the walk can pull it into the closure.
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'dog' => 'Dog']],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Pet']);

    expect($closure->schemas)->toContain('Dog');
});

it('ignores an empty discriminator mapping target', function () {
    // An empty mapping value resolves to null and must not pull a phantom schema
    // into the closure (and must not crash). Only Cat (the oneOf member) survives.
    $document = closureDocument([
        'Pet' => [
            'oneOf' => [['$ref' => '#/components/schemas/Cat']],
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'blank' => '']],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Pet']);

    expect($closure->schemas)->toBe(['Cat', 'Pet']);
});

it('pulls all three siblings in when one variant of a three-way union is selected', function () {
    $document = closureDocument([
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
                ['$ref' => '#/components/schemas/Fish'],
            ],
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'dog' => 'Dog', 'fish' => 'Fish']],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
        'Fish' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'blub' => ['type' => 'string']]],
    ]);

    // Selecting Cat must pull in the base AND both siblings (Dog, Fish): the
    // base's morph() arms reference all three, so dropping any breaks the union.
    $closure = resolveClosure($document, schemas: ['Cat']);

    expect($closure->schemas)->toBe(['Cat', 'Dog', 'Fish', 'Pet']);
});

it('keeps an operation that carries more than one selected tag once', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], [
        '/pets' => [
            'get' => [
                'tags' => ['pets', 'public'],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                ]],
            ],
        ],
    ]);

    // Both tags are selected and both must be marked matched (no unknown), and
    // the single operation is kept exactly once.
    $closure = resolveClosure($document, tags: ['pets', 'public']);

    expect($closure->hasUnknown())->toBeFalse()
        ->and($closure->operationKeys)->toBe(['GET /pets'])
        ->and($closure->schemas)->toBe(['Pet']);
});

it('matches a tag declared with surrounding whitespace in the spec', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], [
        '/pets' => [
            'get' => [
                'tags' => ['  pets  '],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                ]],
            ],
        ],
    ]);

    // The spec's tag is trimmed before comparison, so a clean --only-tags=pets
    // still matches a whitespace-padded tag in the document.
    $closure = resolveClosure($document, tags: ['pets']);

    expect($closure->hasUnknown())->toBeFalse()
        ->and($closure->operationKeys)->toBe(['GET /pets']);
});

it('terminates on a self-referential schema', function () {
    $document = closureDocument([
        'Node' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']],
            ],
        ],
    ]);

    $closure = resolveClosure($document, schemas: ['Node']);

    expect($closure->schemas)->toBe(['Node']);
});

it('terminates on a mutual ref cycle', function () {
    $document = closureDocument([
        'A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/B']]],
        'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A']]],
    ]);

    $closure = resolveClosure($document, schemas: ['A']);

    expect($closure->schemas)->toBe(['A', 'B']);
});

it('follows a multi-hop ref chain A -> B -> C', function () {
    $document = closureDocument([
        'A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/B']]],
        'B' => ['type' => 'object', 'properties' => ['c' => ['$ref' => '#/components/schemas/C']]],
        'C' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]],
        'D' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['A']);

    expect($closure->schemas)->toBe(['A', 'B', 'C']);
});

it('selects operation schemas and their closure by tag', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']]],
        'Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Order' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
    ], [
        '/pets' => [
            'get' => [
                'tags' => ['pets'],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                ]],
            ],
        ],
        '/orders' => [
            'get' => [
                'tags' => ['store'],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Order']]],
                ]],
            ],
        ],
    ]);

    $closure = resolveClosure($document, tags: ['pets']);

    expect($closure->schemas)->toBe(['Pet', 'Tag'])
        ->and($closure->operationKeys)->toBe(['GET /pets'])
        ->and($closure->keepsOperation('get', '/pets'))->toBeTrue()
        ->and($closure->keepsOperation('get', '/orders'))->toBeFalse();
});

it('collects request body, response, and parameter schemas of a tagged operation', function () {
    // Three distinct schemas, one reachable through each operation seam (request
    // body, response, query parameter), so dropping any one collection branch
    // drops a schema from the closure.
    $document = closureDocument([
        'CreatePet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'PetResult' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        'Filter' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
    ], [
        '/pets' => [
            'post' => [
                'tags' => ['pets'],
                'parameters' => [
                    ['name' => 'filter', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Filter']],
                ],
                'requestBody' => [
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CreatePet']]],
                ],
                'responses' => ['201' => [
                    'description' => 'created',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PetResult']]],
                ]],
            ],
        ],
    ]);

    $closure = resolveClosure($document, tags: ['pets']);

    expect($closure->schemas)->toBe(['CreatePet', 'Filter', 'PetResult']);
});

it('unions a tag selection and a schema selection plus the closure of each', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']]],
        'Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Standalone' => ['type' => 'object', 'properties' => ['dep' => ['$ref' => '#/components/schemas/Dep']]],
        'Dep' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]],
        'Ignored' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]],
    ], [
        '/pets' => [
            'get' => [
                'tags' => ['pets'],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                ]],
            ],
        ],
    ]);

    $closure = resolveClosure($document, schemas: ['Standalone'], tags: ['pets']);

    expect($closure->schemas)->toBe(['Dep', 'Pet', 'Standalone', 'Tag']);
});

it('keeps a tagged operation that has no responses, body, or parameters', function () {
    // The operation matches the tag but contributes no schema seed. It must
    // still be kept (its controller/route are generated) and must not crash on
    // the absent responses/body/parameters.
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], [
        '/ping' => ['get' => ['tags' => ['ops']]],
    ]);

    $closure = resolveClosure($document, tags: ['ops']);

    expect($closure->operationKeys)->toBe(['GET /ping'])
        ->and($closure->schemas)->toBe([])
        ->and($closure->hasUnknown())->toBeFalse();
});

it('ignores a tagged operation parameter that has no schema', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], [
        '/pets' => [
            'get' => [
                'tags' => ['pets'],
                // A parameter with no `schema` key must be skipped, not crash.
                'parameters' => [['name' => 'x', 'in' => 'query']],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                ]],
            ],
        ],
    ]);

    $closure = resolveClosure($document, tags: ['pets']);

    expect($closure->schemas)->toBe(['Pet']);
});

it('ignores a non-JSON-only response that still references a schema', function () {
    // A response whose content has an application/json media type contributes
    // its schema; a media type entry is walked regardless of subtype, so a
    // wildcard-ish content still resolves the ref.
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], [
        '/pets' => [
            'get' => [
                'tags' => ['pets'],
                'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/xml' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                ]],
            ],
        ],
    ]);

    $closure = resolveClosure($document, tags: ['pets']);

    expect($closure->schemas)->toBe(['Pet']);
});

it('does not match operations of a tag that is not selected', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']]],
        'Tag' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Order' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
    ], [
        '/pets' => ['get' => ['tags' => ['pets'], 'responses' => ['200' => [
            'description' => 'ok',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
        ]]]],
        '/orders' => ['get' => ['tags' => ['store'], 'responses' => ['200' => [
            'description' => 'ok',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Order']]],
        ]]]],
    ]);

    // Selecting only 'store' must keep the orders operation and Order schema,
    // and must NOT keep the pets operation or its Pet/Tag closure.
    $closure = resolveClosure($document, tags: ['store']);

    expect($closure->operationKeys)->toBe(['GET /orders'])
        ->and($closure->schemas)->toBe(['Order']);
});

it('reports an unknown schema name', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ]);

    $closure = resolveClosure($document, schemas: ['Nope']);

    expect($closure->hasUnknown())->toBeTrue()
        ->and($closure->unknownSchemas)->toBe(['Nope'])
        ->and($closure->schemas)->toBe([]);
});

it('reports an unknown tag name', function () {
    $document = closureDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], [
        '/pets' => ['get' => ['tags' => ['pets'], 'responses' => ['200' => ['description' => 'ok']]]],
    ]);

    $closure = resolveClosure($document, tags: ['ghosts']);

    expect($closure->hasUnknown())->toBeTrue()
        ->and($closure->unknownTags)->toBe(['ghosts']);
});

it('is the all-sentinel when nothing is selected', function () {
    expect(SubsetSelection::all()->isAll())->toBeTrue()
        ->and(SubsetSelection::of([], [])->isAll())->toBeTrue()
        ->and(SubsetSelection::of(['pets'], [])->isAll())->toBeFalse();
});

it('de-duplicates and trims selection input deterministically', function () {
    $selection = SubsetSelection::of([' pets ', 'pets', ''], ['Pet', 'Pet', ' ']);

    expect($selection->tags)->toBe(['pets'])
        ->and($selection->schemas)->toBe(['Pet']);
});
