<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\StubGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return array<string, GeneratedFile>
 */
function generateStubs(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($doc);

    return (new StubGenerator($options))->generate($descriptors);
}

it('emits one concrete stub per controller, keyed by concrete class name', function () {
    expect(array_keys(generateStubs()))
        ->toBe(['PetController', 'UntaggedController']);
});

it('declares a final class extending the generated abstract in the controller namespace', function () {
    $code = generateStubs()['PetController']->code;

    expect($code)->toContain('namespace App\Http\Controllers\Api;')
        ->and($code)->toContain('final class PetController extends AbstractPetController');
});

it('implements every abstract method with the exact abstract signature', function () {
    $code = generateStubs()['PetController']->code;

    // These mirror the abstract signatures one-to-one (minus `abstract` and
    // with a body): a mismatch would be a PHP fatal at class load.
    expect($code)->toContain('public function listPets(ListPetsQueryData $query): DataCollection')
        ->and($code)->toContain('public function createPet(PetWritableData $pet): PetData')
        ->and($code)->toContain('public function getPetById(int $petId): PetData')
        ->and($code)->toContain('public function deletePet(int $petId): void');
});

it('throws a LogicException naming the operation in every stub body', function () {
    $code = generateStubs()['PetController']->code;

    expect($code)->toContain("throw new LogicException('Not implemented: listPets.');")
        ->and($code)->toContain("throw new LogicException('Not implemented: deletePet.');")
        ->and($code)->toContain('use LogicException;');
});

it('repeats the @return generics docblock so collection returns stay PHPStan-clean', function () {
    $code = generateStubs()['PetController']->code;

    expect($code)->toContain('* @return DataCollection<int, PetData>');
});

it('marks the stub as user-owned, one-time output', function () {
    foreach (generateStubs() as $file) {
        expect($file->code)->toContain('This file is yours')
            ->and($file->code)->toContain('never overwritten');
    }
});

it('imports the types the signatures reference, sorted', function () {
    $code = generateStubs()['PetController']->code;

    $imports = [];
    foreach (explode("\n", $code) as $line) {
        if (str_starts_with($line, 'use ')) {
            $imports[] = $line;
        }
    }

    $sorted = $imports;
    sort($sorted);

    expect($imports)->toBe($sorted)
        ->and($imports)->toContain('use App\Data\Pet\ListPetsQueryData;')
        ->and($imports)->toContain('use LogicException;')
        ->and($imports)->toContain('use Spatie\LaravelData\DataCollection;');
});

it('is deterministic: same spec in, byte-identical stubs out', function () {
    $first = array_map(fn (GeneratedFile $f): string => $f->code, generateStubs());
    $second = array_map(fn (GeneratedFile $f): string => $f->code, generateStubs());

    expect($first)->toBe($second);
});

it('produces syntactically valid PHP', function () {
    foreach (generateStubs() as $file) {
        expect(fn () => token_get_all($file->code, TOKEN_PARSE))->not->toThrow(Throwable::class);
    }
});
