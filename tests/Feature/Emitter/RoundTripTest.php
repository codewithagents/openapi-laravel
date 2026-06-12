<?php

declare(strict_types=1);

use App\Data\CustomerData;
use App\Data\CustomerStatus;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;
use Illuminate\Validation\ValidationException;

/**
 * End-to-end: generate from a spec, load the emitted classes into a booted
 * Laravel app, and hydrate a Data object from a real payload. Proves the
 * output is not just syntactically valid but behaves as laravel-data classes.
 */
beforeEach(function () {
    $document = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/emitter/customer.json');
    $files = (new ModelGenerator)->generate($document);

    $dir = sys_get_temp_dir().'/oal_roundtrip_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ($files as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }
});

it('hydrates a generated Data object from a payload', function () {
    $customer = CustomerData::from([
        'id' => 7,
        'name' => 'Ada',
        'email_address' => 'ada@example.com',
        'status' => 'active',
        'tags' => [['label' => 'vip'], ['label' => 'beta']],
        'address' => ['city' => 'Berlin', 'zip' => '10115'],
    ]);

    expect($customer->id)->toBe(7)
        ->and($customer->name)->toBe('Ada')
        ->and($customer->emailAddress)->toBe('ada@example.com')
        ->and($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->address->city)->toBe('Berlin')
        ->and($customer->tags[0]->label)->toBe('vip')
        ->and($customer->tags[1]->label)->toBe('beta');
});

it('treats absent optional properties as null', function () {
    $customer = CustomerData::from([
        'id' => 1,
        'name' => 'Grace',
    ]);

    expect($customer->emailAddress)->toBeNull()
        ->and($customer->status)->toBeNull()
        ->and($customer->address)->toBeNull();
});

it('passes validation for a valid payload', function () {
    CustomerData::validate(['id' => 1, 'name' => 'Ada', 'email_address' => 'ada@example.com']);

    expect(true)->toBeTrue();
});

it('rejects a missing required field', function () {
    CustomerData::validate(['name' => 'Ada']);
})->throws(ValidationException::class);

it('rejects an invalid email format', function () {
    CustomerData::validate(['id' => 1, 'name' => 'Ada', 'email_address' => 'not-an-email']);
})->throws(ValidationException::class);

it('rejects a number below the minimum', function () {
    CustomerData::validate(['id' => 0, 'name' => 'Ada']);
})->throws(ValidationException::class);

it('serialises back to the wire shape', function () {
    $array = CustomerData::from([
        'id' => 2,
        'name' => 'Linus',
        'email_address' => 'linus@example.com',
    ])->toArray();

    expect($array)->toHaveKey('email_address')
        ->and($array['email_address'])->toBe('linus@example.com');
});
