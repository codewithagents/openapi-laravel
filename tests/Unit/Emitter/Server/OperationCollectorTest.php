<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return list<OperationDescriptor>
 */
function collectPetstore(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    return (new OperationCollector(new ServerOptions, $generator->registry()))->collect($doc);
}

/**
 * @param  list<OperationDescriptor>  $descriptors
 */
function descriptorFor(array $descriptors, string $method, string $path): OperationDescriptor
{
    foreach ($descriptors as $descriptor) {
        if ($descriptor->httpMethod === $method && $descriptor->path === $path) {
            return $descriptor;
        }
    }

    throw new RuntimeException("No descriptor for {$method} {$path}");
}

it('groups operations under a controller derived from the first tag', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets');

    expect($get->controllerClass)->toBe('PetController')
        ->and($get->abstractClass)->toBe('AbstractPetController');
});

it('falls back to a Default controller when an operation has no tag', function () {
    $health = descriptorFor(collectPetstore(), 'get', '/health');

    // "Default" is a reserved word, so the identifier is escaped to _Default.
    expect($health->controllerClass)->toBe('_DefaultController')
        ->and($health->abstractClass)->toBe('Abstract_DefaultController');
});

it('uses the operationId for the method name when present', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($get->methodName)->toBe('getPetById');
});

it('derives a deterministic method name when operationId is absent', function () {
    $health = descriptorFor(collectPetstore(), 'get', '/health');

    expect($health->methodName)->toBe('getHealth');
});

it('types integer path params as int and others as string', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($get->pathParams)->toBe([['name' => 'petId', 'phpType' => 'int']]);
});

it('types a $ref request body against the writable variant class', function () {
    $post = descriptorFor(collectPetstore(), 'post', '/pets');

    expect($post->bodyParam)->toBe(['name' => 'pet', 'type' => 'PetWritableData'])
        ->and($post->bodyRequiresRequest)->toBeFalse()
        ->and($post->imports)->toContain('App\\Data\\PetWritableData');
});

it('returns the Data class for a $ref response', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($get->returnType)->toBe('PetData')
        ->and($get->returnDoc)->toBeNull();
});

it('returns a DataCollection for an array-of-ref response', function () {
    $list = descriptorFor(collectPetstore(), 'get', '/pets');

    expect($list->returnType)->toBe('\\Spatie\\LaravelData\\DataCollection')
        ->and($list->returnDoc)->toBe('DataCollection<int, PetData>')
        ->and($list->imports)->toContain('Spatie\\LaravelData\\DataCollection')
        ->and($list->imports)->toContain('App\\Data\\PetData');
});

it('falls back to JsonResponse when the success response has no typed body', function () {
    $delete = descriptorFor(collectPetstore(), 'delete', '/pets/{petId}');

    expect($delete->returnType)->toBe('\\Illuminate\\Http\\JsonResponse')
        ->and($delete->imports)->toContain('Illuminate\\Http\\JsonResponse');
});

it('orders descriptors by path then by a fixed HTTP-method order', function () {
    $descriptors = collectPetstore();

    $keys = array_map(fn (OperationDescriptor $d): string => $d->httpMethod.' '.$d->path, $descriptors);

    expect($keys)->toBe([
        'get /health',
        'get /pets',
        'post /pets',
        'get /pets/{petId}',
        'delete /pets/{petId}',
    ]);
});

it('is deterministic: same spec in, identical descriptors out', function () {
    $first = array_map(fn (OperationDescriptor $d): string => $d->methodName, collectPetstore());
    $second = array_map(fn (OperationDescriptor $d): string => $d->methodName, collectPetstore());

    expect($first)->toBe($second);
});
