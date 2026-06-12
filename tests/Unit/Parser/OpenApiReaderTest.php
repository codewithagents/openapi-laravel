<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ComponentsNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\MediaTypeNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\PathItemNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\RequestBodyNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponseNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SecurityRequirementNode;

/**
 * #104 T2: OpenApiReader hydrates the decoded raw spec array into the typed
 * value-object graph from T1, folding SchemaNormalizer's rewrites and
 * reproducing SpecParser's structural rejection and exact version gating
 * (#103). These tests pin the hydration shapes, the presence semantics, the
 * normalization parity, and the rejection messages.
 */
function readSpec(array $overrides = []): OpenApiDocument
{
    $base = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
    ];

    return (new OpenApiReader)->read([...$base, ...$overrides], 'test-spec');
}

/** Hydrate a single component schema and return its node. */
function readSchema(array|bool $schema): SchemaNode|ReferenceNode
{
    $document = readSpec(['components' => ['schemas' => ['Subject' => $schema]]]);

    $node = $document->components?->schemas['Subject'] ?? null;
    assert($node instanceof SchemaNode || $node instanceof ReferenceNode);

    return $node;
}

// --- Structural rejection and version gating (#103 parity) -----------------

it('rejects non-array input as a missing openapi version string', function () {
    (new OpenApiReader)->read('not a document', 'bad.yaml');
})->throws(ParseException::class, "missing 'openapi' version string");

it('rejects a document without an openapi key, naming the supported matrix', function () {
    (new OpenApiReader)->read(['swagger' => '2.0'], 'swagger.json');
})->throws(ParseException::class, 'Supported versions: OpenAPI 3.0.x and 3.1.x');

it('rejects unsupported versions exactly', function (string $version) {
    expect(fn () => (new OpenApiReader)->read(['openapi' => $version, 'info' => ['title' => 'T', 'version' => '1']], 's'))
        ->toThrow(ParseException::class, "Unsupported OpenAPI version '{$version}'");
})->with(['2.0', '3.3.0', '4.0.0', '3.10.1', '30.0.0']);

it('rejects a document missing the info object', function () {
    (new OpenApiReader)->read(['openapi' => '3.1.0'], 's');
})->throws(ParseException::class, "missing required 'info' object");

it('rejects a mistyped info value as a missing info object', function () {
    (new OpenApiReader)->read(['openapi' => '3.1.0', 'info' => 'Petstore'], 's');
})->throws(ParseException::class, "missing required 'info' object");

it('accepts every supported version band without warnings', function (string $version) {
    $document = (new OpenApiReader)->read(['openapi' => $version, 'info' => ['title' => 'T', 'version' => '1']], 's');

    expect($document->openapi)->toBe($version)
        ->and($document->warnings)->toBe([]);
})->with(['3.0.0', '3.0.3', '3.1.0', '3.1.1']);

it('accepts a 3.2 document best-effort with the loud warning naming #102 and the matrix', function () {
    $document = (new OpenApiReader)->read(['openapi' => '3.2.0', 'info' => ['title' => 'T', 'version' => '1']], 'spec-3.2.yaml');

    expect($document->warnings)->toHaveCount(1)
        ->and($document->warnings[0])->toContain("'3.2.0' (spec-3.2.yaml) is accepted best-effort")
        ->and($document->warnings[0])->toContain('issues/102')
        ->and($document->warnings[0])->toContain('Supported versions: OpenAPI 3.0.x and 3.1.x');
});

it('warns per dropped 3.2 construct and still hydrates the typed stubs', function () {
    $document = (new OpenApiReader)->read([
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'paths' => [
            '/pets' => [
                'query' => ['operationId' => 'queryPets'],
                'additionalOperations' => ['COPY' => ['operationId' => 'copyPets']],
                'get' => [
                    'operationId' => 'streamPets',
                    'responses' => [
                        '200' => ['description' => 'ok', 'content' => [
                            'application/jsonl' => ['itemSchema' => ['type' => 'object']],
                        ]],
                    ],
                ],
            ],
        ],
    ], 's');

    $pathItem = $document->paths['/pets'];
    $media = $pathItem->get?->responses?->get('200');
    assert($media instanceof ResponseNode);

    expect($document->warnings)->toHaveCount(4)
        ->and($document->warnings[1])->toContain('`query` operation at paths./pets was dropped')
        ->and($document->warnings[2])->toContain('`additionalOperations` at paths./pets were dropped')
        ->and($document->warnings[3])->toContain('`itemSchema` at paths./pets.get.responses.200.content.application/jsonl was dropped')
        ->and($pathItem->query?->operationId)->toBe('queryPets')
        ->and($pathItem->additionalOperations)->toHaveKey('COPY')
        ->and($pathItem->operations())->toHaveKeys(['query', 'copy', 'get'])
        ->and($media->content['application/jsonl']->itemSchema)->toBeInstanceOf(SchemaNode::class);
});

// --- Document, info, paths, components --------------------------------------

it('hydrates a minimal document', function () {
    $document = readSpec();

    expect($document->openapi)->toBe('3.1.0')
        ->and($document->info->title)->toBe('T')
        ->and($document->info->version)->toBe('1.0.0')
        ->and($document->paths)->toBe([])
        ->and($document->components)->toBeNull()
        ->and($document->security)->toBeNull()
        ->and($document->tags)->toBeNull()
        ->and($document->servers)->toBeNull();
});

it('hydrates the full operation surface', function () {
    $document = readSpec([
        'paths' => [
            '/pets/{petId}' => [
                'summary' => 'pet ops',
                'parameters' => [
                    ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['$ref' => '#/components/parameters/Page'],
                ],
                'get' => [
                    'operationId' => 'getPet',
                    'tags' => ['pets', 7, 'animals'],
                    'deprecated' => true,
                    'responses' => [
                        200 => ['description' => 'ok', 'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']],
                        ]],
                        'default' => ['$ref' => '#/components/responses/Error'],
                        'x-codegen-hint' => 'ignored',
                    ],
                ],
                'put' => [
                    'operationId' => 'putPet',
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                    ],
                ],
                'post' => [
                    'operationId' => 'postPet',
                    'requestBody' => ['$ref' => '#/components/requestBodies/PetBody'],
                ],
            ],
        ],
    ]);

    $pathItem = $document->paths['/pets/{petId}'];
    $get = $pathItem->get;

    expect($pathItem)->toBeInstanceOf(PathItemNode::class)
        ->and($pathItem->summary)->toBe('pet ops')
        ->and($pathItem->parameters[0])->toBeInstanceOf(ParameterNode::class)
        ->and($pathItem->parameters[0]->name)->toBe('petId')
        ->and($pathItem->parameters[0]->required)->toBeTrue()
        ->and($pathItem->parameters[0]->schema)->toBeInstanceOf(SchemaNode::class)
        ->and($pathItem->parameters[1])->toBeInstanceOf(ReferenceNode::class)
        ->and($get?->operationId)->toBe('getPet')
        ->and($get->tags)->toBe(['pets', 'animals'])
        ->and($get->deprecated)->toBeTrue()
        ->and($get->responses?->statusCodes())->toBe(['200', 'default'])
        ->and($get->responses->get('200'))->toBeInstanceOf(ResponseNode::class)
        ->and($get->responses->get('default'))->toBeInstanceOf(ReferenceNode::class)
        ->and($get->responses->extensions)->toBe(['x-codegen-hint' => 'ignored'])
        ->and($pathItem->put?->requestBody)->toBeInstanceOf(RequestBodyNode::class)
        ->and($pathItem->put->requestBody->required)->toBeTrue()
        ->and($pathItem->post?->requestBody)->toBeInstanceOf(ReferenceNode::class)
        ->and($pathItem->post->requestBody->pointer())->toBe('#/components/requestBodies/PetBody');
});

it('hydrates components sections typed, keeping unknown sections in extra', function () {
    $document = readSpec([
        'components' => [
            'schemas' => ['Pet' => ['type' => 'object'], 'Alias' => ['$ref' => '#/components/schemas/Pet']],
            'responses' => ['Error' => ['description' => 'err']],
            'parameters' => ['Page' => ['name' => 'page', 'in' => 'query']],
            'requestBodies' => ['PetBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
            'securitySchemes' => ['api_key' => ['type' => 'apiKey', 'name' => 'X-Key', 'in' => 'header']],
            'headers' => ['RateLimit' => ['schema' => ['type' => 'integer']]],
            'x-internal' => true,
        ],
    ]);

    $components = $document->components;
    assert($components instanceof ComponentsNode);

    expect($components->schemas['Pet'])->toBeInstanceOf(SchemaNode::class)
        ->and($components->schemas['Alias'])->toBeInstanceOf(ReferenceNode::class)
        ->and($components->responses['Error'])->toBeInstanceOf(ResponseNode::class)
        ->and($components->parameters['Page'])->toBeInstanceOf(ParameterNode::class)
        ->and($components->requestBodies['PetBody'])->toBeInstanceOf(RequestBodyNode::class)
        ->and($components->securitySchemes['api_key']['type'])->toBe('apiKey')
        ->and($components->extra)->toHaveKey('headers')
        ->and($components->extra)->not->toHaveKey('schemas')
        ->and($components->extensions)->toBe(['x-internal' => true]);
});

it('hydrates webhooks as path items or references', function () {
    $document = readSpec([
        'webhooks' => [
            'newPet' => ['post' => ['operationId' => 'newPetHook']],
            'shared' => ['$ref' => '#/components/pathItems/Shared'],
        ],
    ]);

    expect($document->webhooks['newPet'])->toBeInstanceOf(PathItemNode::class)
        ->and($document->webhooks['shared'])->toBeInstanceOf(ReferenceNode::class);
});

it('keeps document-level extensions and raw tag and server lists', function () {
    $document = readSpec([
        'tags' => [['name' => 'pets', 'description' => 'd'], 'mistyped'],
        'servers' => [['url' => 'https://api.example.com']],
        'x-audience' => 'internal',
    ]);

    expect($document->tags)->toBe([['name' => 'pets', 'description' => 'd']])
        ->and($document->servers)->toBe([['url' => 'https://api.example.com']])
        ->and($document->extensions)->toBe(['x-audience' => 'internal']);
});

// --- Security presence semantics (#77 parity) --------------------------------

it('keeps the three-way security presence semantics on document and operation', function () {
    $document = readSpec([
        'security' => [['petstore_auth' => ['read:pets']], []],
        'paths' => [
            '/a' => ['get' => ['operationId' => 'a']],
            '/b' => ['get' => ['operationId' => 'b', 'security' => []]],
            '/c' => ['get' => ['operationId' => 'c', 'security' => [['api_key' => []]]]],
        ],
    ]);

    $declared = $document->paths['/c']->get?->security[0] ?? null;

    expect($document->security)->toHaveCount(2)
        ->and($document->security[0]->schemes)->toBe(['petstore_auth' => ['read:pets']])
        ->and($document->security[1]->schemes)->toBe([])
        ->and($document->paths['/a']->get?->security)->toBeNull()
        ->and($document->paths['/b']->get?->security)->toBe([])
        ->and($declared)->toBeInstanceOf(SecurityRequirementNode::class)
        ->and($declared->schemeNames())->toBe(['api_key']);
});

// --- Schema keyword hydration and presence semantics -------------------------

it('hydrates schema $refs with 3.1 siblings as reference nodes', function () {
    $node = readSchema(['$ref' => '#/components/schemas/Pet', 'summary' => 's', 'description' => 'd']);

    assert($node instanceof ReferenceNode);
    expect($node->pointer())->toBe('#/components/schemas/Pet')
        ->and($node->summary)->toBe('s')
        ->and($node->description)->toBe('d');
});

it('hydrates the scalar keywords and 3.1 type arrays', function () {
    $node = readSchema([
        'type' => ['string', 'null'],
        'format' => 'date-time',
        'title' => 'When',
        'pattern' => '^x',
        'minLength' => 1,
        'maxLength' => 10,
        'deprecated' => true,
        'readOnly' => true,
    ]);

    assert($node instanceof SchemaNode);
    expect($node->type)->toBe(['string', 'null'])
        ->and($node->format)->toBe('date-time')
        ->and($node->title)->toBe('When')
        ->and($node->pattern)->toBe('^x')
        ->and($node->minLength)->toBe(1)
        ->and($node->maxLength)->toBe(10)
        ->and($node->deprecated)->toBeTrue()
        ->and($node->readOnly)->toBeTrue();
});

it('distinguishes explicit null default and const from absence', function () {
    $absent = readSchema(['type' => 'string']);
    $explicit = readSchema(['type' => 'string', 'default' => null, 'const' => null]);
    $valued = readSchema(['type' => 'string', 'default' => 'pending', 'const' => 'dog']);

    assert($absent instanceof SchemaNode && $explicit instanceof SchemaNode && $valued instanceof SchemaNode);
    expect($absent->hasDefault)->toBeFalse()
        ->and($absent->hasConst)->toBeFalse()
        ->and($explicit->hasDefault)->toBeTrue()
        ->and($explicit->default)->toBeNull()
        ->and($explicit->hasConst)->toBeTrue()
        ->and($explicit->const)->toBeNull()
        ->and($valued->default)->toBe('pending')
        ->and($valued->const)->toBe('dog');
});

it('distinguishes explicit additionalProperties from absence', function () {
    $absent = readSchema(['type' => 'object']);
    $closed = readSchema(['type' => 'object', 'additionalProperties' => false]);
    $typed = readSchema(['type' => 'object', 'additionalProperties' => ['type' => 'string']]);

    assert($absent instanceof SchemaNode && $closed instanceof SchemaNode && $typed instanceof SchemaNode);
    expect($absent->hasAdditionalProperties)->toBeFalse()
        ->and($absent->additionalProperties)->toBeNull()
        ->and($closed->hasAdditionalProperties)->toBeTrue()
        ->and($closed->additionalProperties)->toBeFalse()
        ->and($typed->hasAdditionalProperties)->toBeTrue()
        ->and($typed->additionalProperties)->toBeInstanceOf(SchemaNode::class);
});

it('keeps both exclusive bound forms and the explicit false', function () {
    $boolForm = readSchema(['minimum' => 5, 'exclusiveMinimum' => true]);
    $explicitFalse = readSchema(['minimum' => 5, 'exclusiveMinimum' => false]);
    $numericForm = readSchema(['exclusiveMinimum' => 5, 'exclusiveMaximum' => '9.5']);
    $absent = readSchema(['minimum' => 5]);

    assert($boolForm instanceof SchemaNode && $explicitFalse instanceof SchemaNode && $numericForm instanceof SchemaNode && $absent instanceof SchemaNode);
    expect($boolForm->exclusiveMinimum)->toBeTrue()
        ->and($explicitFalse->exclusiveMinimum)->toBeFalse()
        ->and($numericForm->exclusiveMinimum)->toBe(5)
        ->and($numericForm->exclusiveMaximum)->toBe(9.5)
        ->and($absent->exclusiveMinimum)->toBeNull();
});

it('hydrates the 3.1 keywords first-class', function () {
    $node = readSchema([
        'type' => 'object',
        'properties' => ['card' => ['type' => 'string']],
        'patternProperties' => ['^x-' => ['type' => 'string']],
        'dependentRequired' => ['card' => ['billing', 7]],
        'contentMediaType' => 'image/png',
        'prefixItems' => [['type' => 'integer'], ['$ref' => '#/components/schemas/Pet']],
    ]);

    assert($node instanceof SchemaNode);
    expect($node->properties)->toHaveKey('card')
        ->and($node->patternProperties)->toHaveKey('^x-')
        ->and($node->patternProperties['^x-'])->toBeInstanceOf(SchemaNode::class)
        ->and($node->dependentRequired)->toBe(['card' => ['billing']])
        ->and($node->contentMediaType)->toBe('image/png')
        ->and($node->prefixItems)->toHaveCount(2)
        ->and($node->prefixItems[1])->toBeInstanceOf(ReferenceNode::class);
});

it('hydrates composition lists and discriminators with the 3.2 defaultMapping', function () {
    $node = readSchema([
        'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
        'discriminator' => [
            'propertyName' => 'petType',
            'mapping' => ['cat' => '#/components/schemas/Cat'],
            'defaultMapping' => '#/components/schemas/Dog',
        ],
    ]);

    assert($node instanceof SchemaNode);
    expect($node->oneOf)->toHaveCount(2)
        ->and($node->discriminator?->propertyName)->toBe('petType')
        ->and($node->discriminator->mapping)->toBe(['cat' => '#/components/schemas/Cat'])
        ->and($node->discriminator->defaultMapping)->toBe('#/components/schemas/Dog');
});

it('keeps the boolean required misuse and filters proper required lists', function () {
    $proper = readSchema(['type' => 'object', 'required' => ['id', 5, 'name']]);
    $misuse = readSchema(['type' => 'string', 'required' => true]);

    assert($proper instanceof SchemaNode && $misuse instanceof SchemaNode);
    expect($proper->required)->toBe(['id', 'name'])
        ->and($misuse->required)->toBeTrue();
});

it('types the vendor deprecation extensions and keeps all x- keys raw', function () {
    $node = readSchema([
        'type' => 'string',
        'deprecated' => true,
        'x-deprecated-reason' => 'use NewPet',
        'x-deprecation-reason' => 'legacy',
        'x-internal' => true,
    ]);

    assert($node instanceof SchemaNode);
    expect($node->xDeprecatedReason)->toBe('use NewPet')
        ->and($node->xDeprecationReason)->toBe('legacy')
        ->and($node->extensions)->toBe([
            'x-deprecated-reason' => 'use NewPet',
            'x-deprecation-reason' => 'legacy',
            'x-internal' => true,
        ]);
});

it('routes unknown and mistyped keywords to the extra bag', function () {
    $node = readSchema([
        'type' => 'string',
        'examples' => ['a', 'b'],
        '$defs' => ['X' => ['type' => 'string']],
        'format' => 42,
        'maxLength' => 'ten',
    ]);

    assert($node instanceof SchemaNode);
    expect($node->extra)->toBe([
        'examples' => ['a', 'b'],
        '$defs' => ['X' => ['type' => 'string']],
        'format' => 42,
        'maxLength' => 'ten',
    ])
        ->and($node->format)->toBeNull()
        ->and($node->maxLength)->toBeNull();
});

it('hydrates boolean subschemas to their JSON-Schema equivalents', function () {
    $node = readSchema([
        'type' => 'object',
        'properties' => ['anything' => true, 'nothing' => false],
    ]);

    assert($node instanceof SchemaNode);
    $anything = $node->properties['anything'] ?? null;
    $nothing = $node->properties['nothing'] ?? null;

    assert($anything instanceof SchemaNode && $nothing instanceof SchemaNode);
    expect($anything->not)->toBeNull()
        ->and($anything->type)->toBeNull()
        ->and($nothing->not)->toBeInstanceOf(SchemaNode::class);
});

// --- SchemaNormalizer parity (#20, #32, #33, #82) ----------------------------

it('rewrites items: true to an empty schema node', function () {
    $node = readSchema(['type' => 'array', 'items' => true]);

    assert($node instanceof SchemaNode);
    expect($node->items)->toBeInstanceOf(SchemaNode::class)
        ->and($node->items->type)->toBeNull()
        ->and($node->extra)->toBe([]);
});

it('drops items: false and synthesizes maxItems from the closed tuple size', function () {
    $node = readSchema([
        'type' => 'array',
        'prefixItems' => [['type' => 'integer'], ['type' => 'string']],
        'items' => false,
    ]);

    assert($node instanceof SchemaNode);
    expect($node->items)->toBeNull()
        ->and($node->maxItems)->toBe(2)
        ->and($node->prefixItems)->toHaveCount(2);
});

it('drops items: false without prefixItems and synthesizes nothing', function () {
    $node = readSchema(['type' => 'array', 'items' => false]);

    assert($node instanceof SchemaNode);
    expect($node->items)->toBeNull()
        ->and($node->maxItems)->toBeNull();
});

it('tightens a larger explicit maxItems to the closed tuple size', function () {
    $node = readSchema([
        'prefixItems' => [['type' => 'integer'], ['type' => 'string']],
        'items' => false,
        'maxItems' => 9,
    ]);

    assert($node instanceof SchemaNode);
    expect($node->maxItems)->toBe(2);
});

it('keeps a tighter explicit maxItems under a closed tuple, coercing a numeric string', function () {
    $node = readSchema([
        'prefixItems' => [['type' => 'integer'], ['type' => 'string'], ['type' => 'boolean']],
        'items' => false,
        'maxItems' => '2',
    ]);

    assert($node instanceof SchemaNode);
    expect($node->maxItems)->toBe(2);
});

it('replaces a malformed maxItems with the closed tuple size', function () {
    $node = readSchema([
        'prefixItems' => [['type' => 'integer']],
        'items' => false,
        'maxItems' => 'lots',
    ]);

    assert($node instanceof SchemaNode);
    expect($node->maxItems)->toBe(1);
});

it('coerces strictly-numeric constraint strings like the normalizer', function () {
    $node = readSchema([
        'type' => 'number',
        'minimum' => '8',
        'maximum' => '9007199254740993',
        'multipleOf' => '0.5',
        'minLength' => '3',
    ]);

    assert($node instanceof SchemaNode);
    expect($node->minimum)->toBe(8)
        ->and($node->maximum)->toBe(9007199254740993)
        ->and($node->multipleOf)->toBe(0.5)
        ->and($node->minLength)->toBe(3);
});

it('keeps an out-of-int-range numeric string as a float', function () {
    $node = readSchema(['type' => 'integer', 'maximum' => '99999999999999999999999999']);

    assert($node instanceof SchemaNode);
    expect($node->maximum)->toBeFloat()
        ->and($node->maximum)->toBe(1.0E+26);
});

it('routes a non-numeric constraint string to extra, never coercing', function () {
    $node = readSchema(['type' => 'integer', 'minimum' => '8abc']);

    assert($node instanceof SchemaNode);
    expect($node->minimum)->toBeNull()
        ->and($node->extra)->toBe(['minimum' => '8abc']);
});

it('coerces nullable strings case-insensitively and keeps booleans', function () {
    $stringTrue = readSchema(['type' => 'string', 'nullable' => 'TRUE']);
    $stringFalse = readSchema(['type' => 'string', 'nullable' => 'false']);
    $boolean = readSchema(['type' => 'string', 'nullable' => true]);
    $junk = readSchema(['type' => 'string', 'nullable' => 'maybe']);

    assert($stringTrue instanceof SchemaNode && $stringFalse instanceof SchemaNode && $boolean instanceof SchemaNode && $junk instanceof SchemaNode);
    expect($stringTrue->nullable)->toBeTrue()
        ->and($stringFalse->nullable)->toBeFalse()
        ->and($boolean->nullable)->toBeTrue()
        ->and($junk->nullable)->toBeNull()
        ->and($junk->extra)->toBe(['nullable' => 'maybe']);
});

it('normalizes boolean items at any nesting depth', function () {
    $node = readSchema([
        'type' => 'object',
        'properties' => [
            'matrix' => [
                'type' => 'array',
                'items' => ['type' => 'array', 'items' => true],
            ],
        ],
    ]);

    assert($node instanceof SchemaNode);
    $inner = $node->properties['matrix'] ?? null;
    assert($inner instanceof SchemaNode);
    $innerItems = $inner->items;
    assert($innerItems instanceof SchemaNode);

    expect($innerItems->items)->toBeInstanceOf(SchemaNode::class);
});

// --- Depth bound -------------------------------------------------------------

it('rejects schema nesting beyond the configured depth bound', function () {
    $schema = ['type' => 'string'];
    for ($i = 0; $i < 12; $i++) {
        $schema = ['type' => 'object', 'properties' => ['next' => $schema]];
    }

    (new OpenApiReader(maxDepth: 10))->read([
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'components' => ['schemas' => ['Deep' => $schema]],
    ], 's');
})->throws(ParseException::class, 'maximum schema nesting depth (10)');

it('hydrates nesting within the depth bound', function () {
    $schema = ['type' => 'string'];
    for ($i = 0; $i < 8; $i++) {
        $schema = ['type' => 'object', 'properties' => ['next' => $schema]];
    }

    $document = (new OpenApiReader(maxDepth: 10))->read([
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'components' => ['schemas' => ['Deep' => $schema]],
    ], 's');

    expect($document->components?->schemas['Deep'])->toBeInstanceOf(SchemaNode::class);
});

// --- Media types and parameters ----------------------------------------------

it('hydrates parameter content maps and examples passthrough', function () {
    $document = readSpec([
        'paths' => [
            '/x' => ['get' => ['operationId' => 'x', 'parameters' => [[
                'name' => 'filter',
                'in' => 'query',
                'style' => 'deepObject',
                'explode' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => ['a' => 1]]],
                'examples' => ['one' => ['value' => 1]],
            ]]]],
        ],
    ]);

    $parameter = $document->paths['/x']->get?->parameters[0] ?? null;
    assert($parameter instanceof ParameterNode);

    expect($parameter->style)->toBe('deepObject')
        ->and($parameter->explode)->toBeTrue()
        ->and($parameter->content)->toHaveKey('application/json')
        ->and($parameter->content['application/json'])->toBeInstanceOf(MediaTypeNode::class)
        ->and($parameter->content['application/json']->example)->toBe(['a' => 1])
        ->and($parameter->examples)->toBe(['one' => ['value' => 1]]);
});

it('skips mistyped path items, parameters, and responses without crashing', function () {
    $document = readSpec([
        'paths' => [
            '/ok' => ['get' => ['operationId' => 'ok', 'parameters' => ['bogus', ['name' => 'q', 'in' => 'query']], 'responses' => ['200' => 'bogus']]],
            '/broken' => 'not a path item',
        ],
        'security' => 'bogus',
    ]);

    $get = $document->paths['/ok']->get;

    expect($document->paths)->toHaveKey('/ok')
        ->and($document->paths)->not->toHaveKey('/broken')
        ->and($get?->parameters)->toHaveCount(1)
        ->and($get->responses?->statusCodes())->toBe([])
        ->and($document->security)->toBe([]);
});
