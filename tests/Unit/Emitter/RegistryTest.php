<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return array<string, array{dataClass: string, writeClass: ?string, kind: 'data'|'enum'}>
 */
function customerRegistry(): array
{
    $doc = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/emitter/customer.json');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    return $generator->registry();
}

it('maps each component schema to its generated read class and kind', function () {
    $registry = customerRegistry();

    expect($registry['Customer']['dataClass'])->toBe('CustomerData')
        ->and($registry['Customer']['kind'])->toBe('data')
        ->and($registry['CustomerStatus']['dataClass'])->toBe('CustomerStatus')
        ->and($registry['CustomerStatus']['kind'])->toBe('enum');
});

it('reports null writeClass for schemas without read/write flags', function () {
    expect(customerRegistry()['Customer']['writeClass'])->toBeNull();
});

it('reports the writable variant class for schemas that split read/write', function () {
    $doc = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    $registry = $generator->registry();

    expect($registry['Pet']['dataClass'])->toBe('PetData')
        ->and($registry['Pet']['writeClass'])->toBe('PetWritableData');
});

it('is empty before generate() runs', function () {
    expect((new ModelGenerator)->registry())->toBe([]);
});
