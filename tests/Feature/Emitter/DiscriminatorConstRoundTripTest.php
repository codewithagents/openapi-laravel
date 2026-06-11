<?php

declare(strict_types=1);

use App\DiscConstData\CatData;
use App\DiscConstData\CatHolderData;
use App\DiscConstData\PetHolderData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for the #disc-const fix: a discriminated-union variant whose
 * discriminator is declared with a `const` pins that value with a membership
 * rule, so validating the VARIANT standalone rejects a mismatched discriminator.
 * Crucially this must NOT destabilize morph routing: validating through the
 * union/base must still select and validate the right variant.
 */
function bootDiscConstClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
            ],
            'discriminator' => [
                'propertyName' => 'petType',
                'mapping' => ['cat' => 'Cat', 'dog' => 'Dog'],
            ],
        ],
        'Cat' => [
            'type' => 'object',
            'required' => ['petType', 'meow'],
            'properties' => [
                'petType' => ['type' => 'string', 'const' => 'cat'],
                'meow' => ['type' => 'string'],
            ],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['petType', 'bark'],
            'properties' => [
                'petType' => ['type' => 'string', 'const' => 'dog'],
                'bark' => ['type' => 'string'],
            ],
        ],
        'PetHolder' => [
            'type' => 'object',
            'required' => ['pet'],
            'properties' => ['pet' => ['$ref' => '#/components/schemas/Pet']],
        ],
        'CatHolder' => [
            'type' => 'object',
            'required' => ['cat'],
            'properties' => ['cat' => ['$ref' => '#/components/schemas/Cat']],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'DiscConst', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\DiscConstData')))->generate($spec);

    $dir = sys_get_temp_dir().'/oal_disc_const_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $ordered = [];
    foreach ($files as $name => $file) {
        if ($name === 'PetData') {
            $ordered = [$name => $file] + $ordered;
        } else {
            $ordered[$name] = $file;
        }
    }

    foreach ($ordered as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }

    $booted = true;
}

beforeEach(fn () => bootDiscConstClasses());

it('morph routing still selects the right variant with a const-pinned discriminator', function () {
    $holder = PetHolderData::from(['pet' => ['petType' => 'cat', 'meow' => 'mrr']]);

    expect($holder->pet)->toBeInstanceOf(CatData::class);
    expect($holder->pet->meow)->toBe('mrr');
});

it('still validates per variant through the union', function () {
    expect(PetHolderData::validate(['pet' => ['petType' => 'cat', 'meow' => 'mrr']]))->toBeArray();

    // Wrong-variant shape (cat without meow) and unmapped value still reject.
    expect(fn () => PetHolderData::validate(['pet' => ['petType' => 'cat', 'bark' => 'woof']]))
        ->toThrow(ValidationException::class);
    expect(fn () => PetHolderData::validate(['pet' => ['petType' => 'unknown']]))
        ->toThrow(ValidationException::class);
});

it('accepts a standalone variant whose discriminator matches its const', function () {
    expect(CatHolderData::validate(['cat' => ['petType' => 'cat', 'meow' => 'mrr']]))->toBeArray();
});

it('rejects a standalone variant whose discriminator does not match its const (#disc-const)', function () {
    // Validating CatData directly (via a $ref-to-Cat holder) with petType=dog
    // must be rejected now that the variant pins its own discriminator const.
    CatHolderData::validate(['cat' => ['petType' => 'dog', 'meow' => 'mrr']]);
})->throws(ValidationException::class);
