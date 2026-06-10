<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return array<string, GeneratedFile>
 */
function generateControllers(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    return (new ControllerGenerator(new ServerOptions, $generator->registry()))->generate($doc);
}

it('emits one abstract controller file per tag, keyed by abstract class name', function () {
    expect(array_keys(generateControllers()))
        ->toBe(['AbstractPetController', 'Abstract_DefaultController']);
});

it('declares the controller namespace and an abstract class', function () {
    $code = generateControllers()['AbstractPetController']->code;

    expect($code)->toContain('namespace App\Http\Controllers\Api;')
        ->and($code)->toContain('abstract class AbstractPetController')
        ->and($code)->not->toContain('extends');
});

it('emits abstract methods with typed body, path params, and return types', function () {
    $code = generateControllers()['AbstractPetController']->code;

    expect($code)->toContain('abstract public function createPet(PetWritableData $pet): PetData;')
        ->and($code)->toContain('abstract public function getPetById(int $petId): PetData;')
        ->and($code)->toContain('abstract public function listPets(): \Spatie\LaravelData\DataCollection;')
        ->and($code)->toContain('abstract public function deletePet(int $petId): \Illuminate\Http\JsonResponse;');
});

it('puts the body param before path params in the signature', function () {
    $code = generateControllers()['AbstractPetController']->code;

    expect($code)->toContain('createPet(PetWritableData $pet)');
});

it('imports the Data classes it references, sorted', function () {
    $code = generateControllers()['AbstractPetController']->code;

    expect($code)->toContain('use App\Data\PetData;')
        ->and($code)->toContain('use App\Data\PetWritableData;')
        ->and($code)->toContain('use Spatie\LaravelData\DataCollection;');

    $petData = strpos($code, 'use App\Data\PetData;');
    $collection = strpos($code, 'use Spatie\LaravelData\DataCollection;');
    expect($petData)->toBeLessThan($collection);
});

it('documents the method with the HTTP verb, path, summary, and collection return', function () {
    $code = generateControllers()['AbstractPetController']->code;

    expect($code)->toContain('* GET /pets')
        ->and($code)->toContain('* List all pets.')
        ->and($code)->toContain('* @return DataCollection<int, PetData>');
});

it('is deterministic: same spec in, byte-identical controllers out', function () {
    $first = array_map(fn ($f) => $f->code, generateControllers());
    $second = array_map(fn ($f) => $f->code, generateControllers());

    expect($first)->toBe($second);
});

it('produces syntactically valid PHP', function () {
    foreach (generateControllers() as $file) {
        expect(fn () => token_get_all($file->code, TOKEN_PARSE))->not->toThrow(Throwable::class);
    }
});
