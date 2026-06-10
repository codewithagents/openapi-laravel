<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;

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
