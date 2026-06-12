<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return array<string, GeneratedFile>
 */
function generateAccount(): array
{
    $doc = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/emitter/readwrite.json');

    return (new ModelGenerator)->generate($doc);
}

it('splits a schema that uses readOnly/writeOnly into two variants', function () {
    expect(array_keys(generateAccount()))->toBe(['AccountData', 'AccountWritableData']);
});

it('drops writeOnly fields from the read variant', function () {
    $code = generateAccount()['AccountData']->code;

    expect($code)->toContain('$id')
        ->and($code)->toContain('$username')
        ->and($code)->toContain('$createdAt')
        ->and($code)->not->toContain('$password');
});

it('drops readOnly fields from the write variant', function () {
    $code = generateAccount()['AccountWritableData']->code;

    expect($code)->toContain('$username')
        ->and($code)->toContain('$password')
        ->and($code)->not->toContain('$id')
        ->and($code)->not->toContain('$createdAt');
});

it('keeps a single class when no read/write flags are present', function () {
    $doc = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/emitter/customer.json');
    $classes = array_keys((new ModelGenerator)->generate($doc));

    expect($classes)->not->toContain('CustomerWritableData');
});
