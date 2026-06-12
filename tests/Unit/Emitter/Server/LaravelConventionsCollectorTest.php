<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The opt-in Laravel-convention method naming (issue #94) at the collector
 * level: the conventional names land in the descriptors (method name AND
 * route name), the per-controller ambiguity rule makes ALL claimants of one
 * conventional name fall back, and the default (option off) stays
 * byte-identical.
 *
 * @param  array<string, mixed>  $paths
 * @return list<OperationDescriptor>
 */
function collectWithConventions(array $paths, bool $laravelConventions = true): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Conventions', 'version' => '1.0.0'],
        'paths' => $paths,
        'components' => [
            'schemas' => [
                'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ],
        ],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);
    assert($spec instanceof OpenApi);

    $generator = new ModelGenerator;
    $generator->generate($spec);

    $options = new ServerOptions(laravelConventions: $laravelConventions);

    return (new OperationCollector($options, $generator->registry(), null, $generator))->collect($spec);
}

/**
 * @param  array<string, mixed>  $methods  HTTP method => operation overrides
 * @return array<string, mixed>
 */
function conventionOps(array $methods, string $tag = 'pet'): array
{
    $operations = [];
    foreach ($methods as $method => $overrides) {
        $operations[$method] = array_merge([
            'tags' => [$tag],
            'responses' => ['200' => ['description' => 'ok']],
        ], $overrides);
    }

    return $operations;
}

/**
 * @param  list<OperationDescriptor>  $descriptors
 */
function conventionDescriptor(array $descriptors, string $method, string $path): OperationDescriptor
{
    foreach ($descriptors as $descriptor) {
        if ($descriptor->httpMethod === $method && $descriptor->path === $path) {
            return $descriptor;
        }
    }

    throw new RuntimeException("No descriptor for {$method} {$path}");
}

it('maps a clean CRUD slice to the conventional method names, with route names following', function () {
    $descriptors = collectWithConventions([
        '/pets' => conventionOps([
            'get' => ['operationId' => 'listPets'],
            'post' => ['operationId' => 'createPet'],
        ]),
        '/pets/{petId}' => conventionOps([
            'get' => ['operationId' => 'getPetById'],
            'patch' => ['operationId' => 'updatePet'],
            'delete' => ['operationId' => 'deletePet'],
        ]),
    ]);

    $expectations = [
        ['get', '/pets', 'index'],
        ['post', '/pets', 'store'],
        ['get', '/pets/{petId}', 'show'],
        ['patch', '/pets/{petId}', 'update'],
        ['delete', '/pets/{petId}', 'destroy'],
    ];

    foreach ($expectations as [$method, $path, $name]) {
        $descriptor = conventionDescriptor($descriptors, $method, $path);
        expect($descriptor->methodName)->toBe($name)
            ->and($descriptor->routeName)->toBe($name);
    }
});

it('keeps operationId-derived names by default (option off, byte-identical descriptors)', function () {
    $paths = [
        '/pets' => conventionOps([
            'get' => ['operationId' => 'listPets'],
            'post' => ['operationId' => 'createPet'],
        ]),
        '/pets/{petId}' => conventionOps([
            'get' => ['operationId' => 'getPetById'],
            'delete' => ['operationId' => 'deletePet'],
        ]),
    ];

    $off = collectWithConventions($paths, laravelConventions: false);

    expect(conventionDescriptor($off, 'get', '/pets')->methodName)->toBe('listPets')
        ->and(conventionDescriptor($off, 'post', '/pets')->methodName)->toBe('createPet')
        ->and(conventionDescriptor($off, 'get', '/pets/{petId}')->methodName)->toBe('getPetById')
        ->and(conventionDescriptor($off, 'delete', '/pets/{petId}')->methodName)->toBe('deletePet');

    // And the off run is identical to a default-constructed ServerOptions run.
    $document = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($document);
    $default = (new OperationCollector(new ServerOptions, $generator->registry()))->collect($document);
    $explicitOff = (new OperationCollector(new ServerOptions(laravelConventions: false), $generator->registry()))->collect($document);

    expect($explicitOff)->toEqual($default);
});

it('makes BOTH claimants fall back when two operations in one controller map to the same conventional name', function () {
    // Two collection GETs under the same tag: both would be `index`, so both
    // keep their operationId-derived name. The unambiguous POST keeps `store`.
    $descriptors = collectWithConventions([
        '/pets' => conventionOps([
            'get' => ['operationId' => 'listPets'],
            'post' => ['operationId' => 'createPet'],
        ]),
        '/strays' => conventionOps([
            'get' => ['operationId' => 'listStrays'],
        ]),
    ]);

    expect(conventionDescriptor($descriptors, 'get', '/pets')->methodName)->toBe('listPets')
        ->and(conventionDescriptor($descriptors, 'get', '/strays')->methodName)->toBe('listStrays')
        ->and(conventionDescriptor($descriptors, 'post', '/pets')->methodName)->toBe('store');
});

it('makes a PUT and a PATCH on the same item path both fall back (both map to update)', function () {
    $descriptors = collectWithConventions([
        '/pets/{petId}' => conventionOps([
            'put' => ['operationId' => 'replacePet'],
            'patch' => ['operationId' => 'patchPet'],
        ]),
    ]);

    expect(conventionDescriptor($descriptors, 'put', '/pets/{petId}')->methodName)->toBe('replacePet')
        ->and(conventionDescriptor($descriptors, 'patch', '/pets/{petId}')->methodName)->toBe('patchPet');
});

it('scopes the ambiguity rule per controller: each tag keeps its own index, route names get suffixed globally', function () {
    $descriptors = collectWithConventions([
        '/owners' => conventionOps(['get' => ['operationId' => 'listOwners']], tag: 'owner'),
        '/pets' => conventionOps(['get' => ['operationId' => 'listPets']], tag: 'pet'),
    ]);

    $owners = conventionDescriptor($descriptors, 'get', '/owners');
    $pets = conventionDescriptor($descriptors, 'get', '/pets');

    // Method names are per controller, so both controllers own an `index`.
    // Route names are global, so the second one (descriptor order is by path:
    // /owners before /pets) is suffixed.
    expect($owners->methodName)->toBe('index')
        ->and($pets->methodName)->toBe('index')
        ->and($owners->controllerClass)->toBe('OwnerController')
        ->and($pets->controllerClass)->toBe('PetController')
        ->and($owners->routeName)->toBe('index')
        ->and($pets->routeName)->toBe('index_2');
});

it('shares one conventional-name budget when two tags normalize to the same controller class', function () {
    // 'pet store' and 'PetStore' both become PetStoreController, so their two
    // collection GETs claim the same `index` and both must fall back.
    $descriptors = collectWithConventions([
        '/pets' => conventionOps(['get' => ['operationId' => 'listPets']], tag: 'pet store'),
        '/strays' => conventionOps(['get' => ['operationId' => 'listStrays']], tag: 'PetStore'),
    ]);

    expect(conventionDescriptor($descriptors, 'get', '/pets')->methodName)->toBe('listPets')
        ->and(conventionDescriptor($descriptors, 'get', '/strays')->methodName)->toBe('listStrays');
});

it('always falls back for non-CRUD operations (POST on an item path)', function () {
    $descriptors = collectWithConventions([
        '/pets/{petId}' => conventionOps([
            'post' => ['operationId' => 'clonePet'],
            'get' => ['operationId' => 'getPetById'],
        ]),
    ]);

    expect(conventionDescriptor($descriptors, 'post', '/pets/{petId}')->methodName)->toBe('clonePet')
        ->and(conventionDescriptor($descriptors, 'get', '/pets/{petId}')->methodName)->toBe('show');
});

it('routes a residual clash with an operationId through the per-controller allocator', function () {
    // The collection GET claims `index` conventionally; another operation's
    // operationId is literally `index`. Descriptor order is path-sorted
    // (/pets before /pets/{petId}), so the conventional claim wins the bare
    // name and the operationId-derived one is suffixed deterministically.
    $descriptors = collectWithConventions([
        '/pets' => conventionOps(['get' => ['operationId' => 'listPets']]),
        '/pets/{petId}' => conventionOps(['post' => ['operationId' => 'index']]),
    ]);

    expect(conventionDescriptor($descriptors, 'get', '/pets')->methodName)->toBe('index')
        ->and(conventionDescriptor($descriptors, 'post', '/pets/{petId}')->methodName)->toBe('index_2');
});

it('derives the fallback name from method + path when the ambiguous operation has no operationId', function () {
    $descriptors = collectWithConventions([
        '/pets' => conventionOps(['get' => []]),
        '/strays' => conventionOps(['get' => []]),
    ]);

    expect(conventionDescriptor($descriptors, 'get', '/pets')->methodName)->toBe('getPets')
        ->and(conventionDescriptor($descriptors, 'get', '/strays')->methodName)->toBe('getStrays');
});

it('keeps the query Data class name operationId-derived in both modes', function () {
    $paths = [
        '/pets' => conventionOps([
            'get' => [
                'operationId' => 'listPets',
                'parameters' => [
                    ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
                ],
            ],
        ]),
    ];

    $on = conventionDescriptor(collectWithConventions($paths), 'get', '/pets');
    $off = conventionDescriptor(collectWithConventions($paths, laravelConventions: false), 'get', '/pets');

    // The method name changes, the Data layer does not: a conventional
    // `IndexQueryData` would clash across controllers in the shared Data
    // namespace, so the query class keeps the operationId-derived name.
    expect($on->methodName)->toBe('index')
        ->and($on->queryParam)->not->toBeNull()
        ->and($on->queryParam['type'] ?? null)->toBe('ListPetsQueryData')
        ->and($off->queryParam['type'] ?? null)->toBe('ListPetsQueryData');
});
