<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return array<string, GeneratedFile>
 */
function generateCustomer(): array
{
    $doc = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/emitter/customer.json');

    return (new ModelGenerator)->generate($doc);
}

it('emits one class per schema plus nested objects', function () {
    expect(array_keys(generateCustomer()))
        ->toBe(['CustomerAddressData', 'CustomerData', 'CustomerStatus', 'TagData']);
});

it('orders required properties before optional ones', function () {
    $code = generateCustomer()['CustomerData']->code;

    $id = strpos($code, '$id');
    $name = strpos($code, '$name');
    $email = strpos($code, '$emailAddress');

    expect($id)->toBeLessThan($name)
        ->and($name)->toBeLessThan($email);
});

it('adds #[MapName] only when the wire name differs', function () {
    $code = generateCustomer()['CustomerData']->code;

    expect($code)->toContain("#[MapName('email_address')]")
        ->and($code)->toContain('public readonly ?string $emailAddress = null')
        ->and(substr_count($code, '#[MapName('))->toBe(1);
});

it('emits a typed data collection for arrays of refs', function () {
    $code = generateCustomer()['CustomerData']->code;

    expect($code)->toContain('#[DataCollectionOf(TagData::class)]')
        ->and($code)->toContain('/** @var array<int, TagData> */');
});

it('emits spec-derived validation rules keyed by wire name', function () {
    $code = generateCustomer()['CustomerData']->code;

    expect($code)->toContain("'id' => ['required', 'integer', 'min:1'],")
        ->and($code)->toContain("'name' => ['required', 'string', 'max:255', 'min:1'],")
        ->and($code)->toContain("'email_address' => ['sometimes', 'nullable', 'string', 'email'],")
        ->and($code)->toContain("'website' => ['sometimes', 'string', 'url'],")
        ->and($code)->toContain("'sku' => ['sometimes', 'string', 'regex:#^[A-Z]{3}-")
        ->and($code)->toContain("'status' => ['sometimes', Rule::enum(CustomerStatus::class)],")
        ->and($code)->toContain("'tags' => ['sometimes', 'array', 'max:10'],")
        ->and($code)->toContain('use Illuminate\Validation\Rule;');
});

it('emits a native backed enum with studly case names', function () {
    $code = generateCustomer()['CustomerStatus']->code;

    expect($code)->toContain('enum CustomerStatus: string')
        ->and($code)->toContain("case Active = 'active';")
        ->and($code)->toContain("case PendingReview = 'pending-review';");
});

it('is deterministic: same spec in, byte-identical files out', function () {
    $first = generateCustomer();
    $second = generateCustomer();

    $a = array_map(fn ($f) => $f->code, $first);
    $b = array_map(fn ($f) => $f->code, $second);

    expect($a)->toBe($b);
});

it('matches the committed snapshot', function () {
    $combined = implode("\n", array_map(fn ($f) => $f->code, generateCustomer()));

    expect($combined)->toMatchSnapshot();
});
