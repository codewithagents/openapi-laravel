<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
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
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry()))->collect($doc);

    return (new ControllerGenerator($options))->generate($descriptors);
}

it('emits one abstract controller file per tag, keyed by abstract class name', function () {
    expect(array_keys(generateControllers()))
        ->toBe(['AbstractPetController', 'AbstractUntaggedController']);
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
        ->and($code)->toContain('abstract public function listPets(): DataCollection;')
        ->and($code)->toContain('abstract public function deletePet(int $petId): JsonResponse;');
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

it('references every use import by short name (no unused imports)', function () {
    foreach (generateControllers() as $file) {
        $lines = explode("\n", $file->code);
        $imports = [];
        $body = [];

        foreach ($lines as $line) {
            if (preg_match('/^use (.+);$/', $line, $m) === 1) {
                $fqcn = $m[1];
                $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                $imports[$short] = $line;
            } else {
                $body[] = $line;
            }
        }

        $bodyText = implode("\n", $body);

        foreach ($imports as $short => $useLine) {
            // The short name must appear as a whole word somewhere in the body,
            // so a host project's Pint would not strip the import as unused.
            expect(preg_match('/\b'.preg_quote($short, '/').'\b/', $bodyText))
                ->toBe(1, "Unused import {$useLine} in {$file->filename()}");
        }
    }
});

/**
 * Controllers for the query-parameters fixture, with the model generator
 * wired into the collector so query Data classes exist (issue #63).
 *
 * @return array<string, GeneratedFile>
 */
function generateQueryControllers(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/query-parameters.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($doc);

    return (new ControllerGenerator($options))->generate($descriptors);
}

it('injects the query Data class into a body-less method signature (issue #63)', function () {
    $code = generateQueryControllers()['AbstractWidgetController']->code;

    expect($code)->toContain('abstract public function listWidgets(ListWidgetsQueryData $query): JsonResponse;')
        ->and($code)->toContain('use App\Data\ListWidgetsQueryData;');
});

it('keeps the query class out of a body-carrying signature and points at fromQuery in the docblock', function () {
    $code = generateQueryControllers()['AbstractWidgetController']->code;

    expect($code)->toContain('abstract public function createWidget(WidgetData $widget): JsonResponse;')
        ->and($code)->toContain('* Query parameters: validate and hydrate them with')
        ->and($code)->toContain('* \App\Data\CreateWidgetQueryData::fromQuery($request).')
        // No import for a class referenced only in docblock prose: consumer
        // Pint runs would strip it as unused.
        ->and($code)->not->toContain('use App\Data\CreateWidgetQueryData;');
});

it('keeps the query class out of a Request-fallback signature too', function () {
    $code = generateQueryControllers()['AbstractWidgetController']->code;

    expect($code)->toContain('abstract public function uploadBlob(Request $request): JsonResponse;')
        ->and($code)->toContain('* \App\Data\UploadBlobQueryData::fromQuery($request).');
});

it('orders an injected query param after the body slot and before path params', function () {
    // The fixture has no body+query+path combination in one signature, so this
    // asserts on the petstore fixture instead, where listPets is body-less with
    // a query class while getPetById keeps its path param untouched.
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);
    $options = new ServerOptions;
    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($doc);
    $code = (new ControllerGenerator($options))->generate($descriptors)['AbstractPetController']->code;

    expect($code)->toContain('abstract public function listPets(ListPetsQueryData $query): DataCollection;')
        ->and($code)->toContain('abstract public function getPetById(int $petId): PetData;')
        // createPet carries a body, so its dryRun query class is fromQuery-only.
        ->and($code)->toContain('abstract public function createPet(PetWritableData $pet): PetData;')
        ->and($code)->toContain('* \App\Data\CreatePetQueryData::fromQuery($request).');
});
