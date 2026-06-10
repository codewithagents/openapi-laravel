<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;

it('builds StudlyCaps class names', function (string $input, string $expected) {
    expect(PhpIdentifier::toClassName($input))->toBe($expected);
})->with([
    'simple' => ['Customer', 'Customer'],
    'snake' => ['customer_address', 'CustomerAddress'],
    'kebab' => ['customer-address', 'CustomerAddress'],
    'dotted' => ['calendar.calendars.insert', 'CalendarCalendarsInsert'],
    'spaced' => ['Get User Profile', 'GetUserProfile'],
    'camel preserved' => ['getUserProfile', 'GetUserProfile'],
    'leading digit' => ['123model', '_123model'],
    'apostrophe' => ["user's-pet", 'UsersPet'],
    'empty' => ['---', '_'],
]);

it('escapes reserved words used as class names', function (string $input, string $expected) {
    expect(PhpIdentifier::toClassName($input))->toBe($expected);
})->with([
    'list' => ['list', '_List'],
    'array' => ['Array', '_Array'],
    'string' => ['string', '_String'],
    'enum' => ['enum', '_Enum'],
]);

it('builds camelCase property names', function (string $input, string $expected) {
    expect(PhpIdentifier::toPropertyName($input))->toBe($expected);
})->with([
    'snake' => ['first_name', 'firstName'],
    'kebab' => ['x-rate-limit', 'xRateLimit'],
    'pascal' => ['FirstName', 'firstName'],
    'leading digit' => ['2fa_enabled', '_2faEnabled'],
    'reserved ok as variable' => ['class', 'class'],
    'empty fallback' => ['***', 'value'],
]);

it('resolves $refs to class names', function () {
    expect(PhpIdentifier::refToClassName('#/components/schemas/Customer'))->toBe('Customer')
        ->and(PhpIdentifier::refToClassName('#/components/schemas/Pet', ['Pet' => 'PetRenamed']))->toBe('PetRenamed');
});

it('detects when a #[MapName] is required', function () {
    expect(PhpIdentifier::needsMapName('first_name', 'firstName'))->toBeTrue()
        ->and(PhpIdentifier::needsMapName('name', 'name'))->toBeFalse();
});
