<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;

mutates(UniqueNames::class);

it('returns the candidate when free', function () {
    $names = new UniqueNames;

    expect($names->reserve('Customer'))->toBe('Customer');
});

it('suffixes clashing candidates deterministically', function () {
    $names = new UniqueNames;

    expect($names->reserve('Customer'))->toBe('Customer')
        ->and($names->reserve('Customer'))->toBe('Customer_2')
        ->and($names->reserve('Customer'))->toBe('Customer_3');
});

it('tracks reserved names', function () {
    $names = new UniqueNames;
    $names->reserve('Pet');

    expect($names->taken('Pet'))->toBeTrue()
        ->and($names->taken('Order'))->toBeFalse();
});

it('pre-reserves constructor names so a clashing candidate is suffixed (#21)', function () {
    // The model emitter pre-reserves the framework short-names it imports (Data,
    // ...) so a schema named `Data` cannot shadow the imported base class.
    $names = new UniqueNames(['Data', 'Optional']);

    expect($names->taken('Data'))->toBeTrue()
        ->and($names->taken('Optional'))->toBeTrue()
        ->and($names->reserve('Data'))->toBe('Data_2')
        ->and($names->reserve('Optional'))->toBe('Optional_2')
        ->and($names->reserve('Customer'))->toBe('Customer');
});
