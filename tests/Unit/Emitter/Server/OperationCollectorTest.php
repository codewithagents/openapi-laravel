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
 * @return list<OperationDescriptor>
 */
function collectPathParameters(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/path-parameters.yaml');
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

it('falls back to an Untagged controller when an operation has no tag', function () {
    $health = descriptorFor(collectPetstore(), 'get', '/health');

    // "Untagged" is not reserved, so it stays readable (unlike "Default").
    expect($health->controllerClass)->toBe('UntaggedController')
        ->and($health->abstractClass)->toBe('AbstractUntaggedController');
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

it('types a PathItem-level integer path param as int (issue #66)', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/users/{userId}');

    expect($get->pathParams)->toBe([['name' => 'userId', 'phpType' => 'int']]);
});

it('lets an operation-level parameter override a PathItem-level one with the same name and location', function () {
    // The path level declares userId as integer, the put operation redeclares
    // it as string: per OpenAPI precedence the operation wins.
    $put = descriptorFor(collectPathParameters(), 'put', '/users/{userId}');

    expect($put->pathParams)->toBe([['name' => 'userId', 'phpType' => 'string']]);
});

it('resolves a PathItem-level $ref parameter into components.parameters', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/orders/{orderId}');

    expect($get->pathParams)->toBe([['name' => 'orderId', 'phpType' => 'int']]);
});

it('resolves an operation-level $ref parameter into components.parameters', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/reports/{reportId}');

    expect($get->pathParams)->toBe([['name' => 'reportId', 'phpType' => 'int']]);
});

it('degrades an unresolvable parameter $ref to the string fallback without fataling', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/legacy/{legacyId}');

    expect($get->pathParams)->toBe([['name' => 'legacyId', 'phpType' => 'string']]);
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

    // Short return type matches the use import so the import is never unused.
    expect($list->returnType)->toBe('DataCollection')
        ->and($list->returnDoc)->toBe('DataCollection<int, PetData>')
        ->and($list->imports)->toContain('Spatie\\LaravelData\\DataCollection')
        ->and($list->imports)->toContain('App\\Data\\PetData');
});

it('falls back to JsonResponse when the success response has no typed body', function () {
    $delete = descriptorFor(collectPetstore(), 'delete', '/pets/{petId}');

    // Short return type matches the use import so the import is never unused.
    expect($delete->returnType)->toBe('JsonResponse')
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

it('produces a writable variant whose rules() still enforce required non-readOnly fields', function () {
    // The createPet body param is typed PetWritableData (see the writable-variant
    // body test above). This proves that variant is real and that its generated
    // rules() would validate a request: a missing required name fails, the
    // readOnly id is absent. Full HTTP coverage of the writable body is a
    // follow-up (B1); this asserts the generated validation surface directly.
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $files = $generator->generate($doc);
    $registry = $generator->registry();

    // (a) The writable variant is genuinely produced and recorded in the registry.
    expect($registry['Pet']['writeClass'])->toBe('PetWritableData')
        ->and($files)->toHaveKey('PetWritableData');

    // (b) Its rules() require the non-readOnly required field and omit readOnly id.
    $writableCode = $files['PetWritableData']->code;
    expect($writableCode)->toContain("'name' => ['required', 'string']")
        ->and($writableCode)->not->toContain("'id' =>");
});
