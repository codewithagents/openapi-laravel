<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;
use CodeWithAgents\OpenApiLaravel\Parser\SpecArraySerializer;

/**
 * #104 T3: SpecArraySerializer must be the exact inverse of OpenApiReader's
 * hydration, modulo the SchemaNormalizer rewrites the reader folds in. For a
 * well-formed document, serialize(read(x)) must equal normalize(x) up to key
 * order (cebe maps keys to attributes, so sibling key order is meaningless;
 * order WITHIN maps like properties and paths is preserved by construction).
 * SchemaNormalizer is the oracle: it is what the cebe path feeds the emitter.
 */
function roundTripped(array $document): array
{
    return SpecArraySerializer::toArray((new OpenApiReader)->read($document, 'round-trip'));
}

/** Recursive ksort so the comparison ignores sibling key order only. */
function keySorted(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $sorted = [];
    foreach ($value as $key => $entry) {
        $sorted[$key] = keySorted($entry);
    }

    if (! array_is_list($sorted)) {
        ksort($sorted);
    }

    return $sorted;
}

function expectRoundTripMatchesNormalizer(array $document): void
{
    $normalized = SchemaNormalizer::normalize($document);

    expect(keySorted(roundTripped($document)))->toBe(keySorted($normalized));
}

it('round trips a presence-semantics-heavy schema document exactly', function () {
    expectRoundTripMatchesNormalizer([
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Pet' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'format' => 'int64'],
                        'tag' => ['type' => 'string', 'default' => null],
                        'kind' => ['type' => 'string', 'const' => 'pet'],
                        'age' => ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => false],
                        'score' => ['exclusiveMinimum' => 2, 'exclusiveMaximum' => 9.5],
                    ],
                    'additionalProperties' => false,
                    'discriminator' => ['propertyName' => 'kind', 'mapping' => ['pet' => '#/components/schemas/Pet']],
                ],
                'Tuple' => [
                    'type' => 'array',
                    'prefixItems' => [['type' => 'integer'], ['type' => 'string']],
                    'items' => false,
                ],
                'AnyList' => ['type' => 'array', 'items' => true],
                'Map' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'Weird' => [
                    'type' => 'string',
                    'minLength' => '3',
                    'maximum' => '8abc',
                    'nullable' => 'TRUE',
                    'x-deprecated-reason' => 'gone',
                    '$defs' => ['Inner' => ['type' => 'string']],
                ],
            ],
        ],
    ]);
});

it('round trips paths, operations, responses, and security exactly', function () {
    expectRoundTripMatchesNormalizer([
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1.0.0', 'description' => 'd'],
        'security' => [['global_auth' => ['read']]],
        'tags' => [['name' => 'pets']],
        'servers' => [['url' => 'https://api.example.com']],
        'x-audience' => 'partner',
        'paths' => [
            '/pets/{petId}' => [
                'summary' => 's',
                'parameters' => [
                    ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['$ref' => '#/components/parameters/Page'],
                ],
                'get' => [
                    'operationId' => 'getPet',
                    'tags' => ['pets'],
                    'security' => [],
                    'responses' => [
                        '200' => ['description' => 'ok', 'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']],
                        ]],
                        'default' => ['$ref' => '#/components/responses/Error'],
                    ],
                ],
                'post' => [
                    'operationId' => 'createPet',
                    'security' => [['api_key' => []]],
                    'requestBody' => [
                        'required' => true,
                        'description' => 'payload',
                        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                    ],
                    'responses' => ['201' => ['description' => 'created']],
                ],
            ],
        ],
        'components' => [
            'schemas' => ['Pet' => ['type' => 'object']],
            'responses' => ['Error' => ['description' => 'err', 'headers' => ['X-Trace' => ['schema' => ['type' => 'string']]]]],
            'parameters' => ['Page' => ['name' => 'page', 'in' => 'query', 'style' => 'form', 'explode' => true]],
            'requestBodies' => ['Body' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
            'securitySchemes' => ['api_key' => ['type' => 'apiKey', 'name' => 'X-Key', 'in' => 'header']],
            'headers' => ['RateLimit' => ['schema' => ['type' => 'integer']]],
        ],
    ]);
});

it('round trips 3.2 constructs into the same raw keys the scanner reads', function () {
    expectRoundTripMatchesNormalizer([
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'query' => ['operationId' => 'queryThings', 'responses' => ['200' => ['description' => 'ok']]],
                'additionalOperations' => ['COPY' => ['operationId' => 'copyThings']],
                'get' => ['operationId' => 'streamThings', 'responses' => ['200' => [
                    'description' => 'ok',
                    'content' => ['application/jsonl' => ['itemSchema' => ['type' => 'object']]],
                ]]],
            ],
        ],
    ]);
});

it('round trips webhooks, vendor extensions, and discriminator defaultMapping', function () {
    expectRoundTripMatchesNormalizer([
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'webhooks' => [
            'newPet' => ['post' => ['operationId' => 'hook', 'responses' => ['200' => ['description' => 'ok']]]],
            'shared' => ['$ref' => '#/components/pathItems/Shared'],
        ],
        'components' => [
            'schemas' => [
                'Poly' => [
                    'oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']],
                    'discriminator' => ['propertyName' => 't', 'defaultMapping' => '#/components/schemas/A', 'x-note' => 'n'],
                ],
                'A' => ['type' => 'object', 'x-internal' => true],
                'B' => ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
            ],
        ],
    ]);
});

it('deliberately does not reproduce the normalizer rewrite inside data blobs (documented quirk)', function () {
    // SchemaNormalizer is key-based and blunt: it coerces `nullable`/numeric
    // keys ANYWHERE, including inside `default`, `example`, `const`, and
    // `enum` VALUES, which are user data, not schema keywords. The reader is
    // structural and keeps those blobs verbatim, so this is the one known
    // class where serialize(read(x)) intentionally differs from normalize(x).
    // No corpus spec triggers it (the byte-identical corpus gate is green);
    // if one ever does, the reader's faithful passthrough is the CORRECT
    // behavior and the corpus gate exception must be classified here.
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['X' => [
            'type' => 'object',
            'default' => ['nullable' => 'true', 'minimum' => '8'],
            'example' => ['maxLength' => '10'],
        ]]],
    ];

    $normalized = SchemaNormalizer::normalize($document);
    $roundTripped = roundTripped($document);

    assert(is_array($normalized));
    $normalizedSchema = $normalized['components']['schemas']['X'];
    $roundTrippedSchema = $roundTripped['components']['schemas']['X'];

    // The cebe path mutates the user data inside the blobs.
    expect($normalizedSchema['default'])->toBe(['nullable' => true, 'minimum' => 8])
        ->and($normalizedSchema['example'])->toBe(['maxLength' => 10])
        // The reader path keeps the blobs exactly as written.
        ->and($roundTrippedSchema['default'])->toBe(['nullable' => 'true', 'minimum' => '8'])
        ->and($roundTrippedSchema['example'])->toBe(['maxLength' => '10']);
});
