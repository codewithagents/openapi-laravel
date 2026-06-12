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

it('stays case-sensitive by default so distinct PHP identifiers are not folded (#108)', function () {
    // Property, parameter and enum-case names are case-sensitive PHP identifiers,
    // so `userId` and `UserId` are legitimately distinct and must coexist.
    $names = new UniqueNames;

    expect($names->reserve('userId'))->toBe('userId')
        ->and($names->reserve('UserId'))->toBe('UserId')
        ->and($names->taken('USERID'))->toBeFalse();
});

it('dedupes case-only clashes in case-insensitive mode so filenames cannot collide (#108)', function () {
    // Class names: `FOO` and `Foo` are the SAME PHP class and produce the SAME
    // filename on a case-insensitive filesystem. The first claimant keeps its
    // exact casing; the second takes the suffix path preserving its own casing.
    $names = new UniqueNames(caseInsensitive: true);

    expect($names->reserve('FOO'))->toBe('FOO')
        ->and($names->reserve('Foo'))->toBe('Foo_2')
        ->and($names->reserve('foo'))->toBe('foo_3');
});

it('treats taken() case-insensitively in case-insensitive mode (#108)', function () {
    $names = new UniqueNames(caseInsensitive: true);
    $names->reserve('Pet');

    expect($names->taken('pet'))->toBeTrue()
        ->and($names->taken('PET'))->toBeTrue()
        ->and($names->taken('Order'))->toBeFalse();
});

it('case-folds pre-reserved constructor names in case-insensitive mode (#108)', function () {
    // A schema named `DATA` collides with the imported `Data` alias regardless
    // of casing, so it must take the suffix path.
    $names = new UniqueNames(['Data'], caseInsensitive: true);

    expect($names->taken('data'))->toBeTrue()
        ->and($names->reserve('DATA'))->toBe('DATA_2');
});

it('keeps suffixing deterministically across mixed-case clashes in case-insensitive mode (#108)', function () {
    $names = new UniqueNames(caseInsensitive: true);

    // Same case-folded family, different casings: a single _N sequence.
    expect($names->reserve('User'))->toBe('User')
        ->and($names->reserve('USER'))->toBe('USER_2')
        ->and($names->reserve('user'))->toBe('user_3')
        ->and($names->reserve('uSeR'))->toBe('uSeR_4');
});
