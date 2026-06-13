<?php

declare(strict_types=1);

use App\DiscriminatedData\CatData;
use App\DiscriminatedData\CatHolderData;
use App\DiscriminatedData\DogData;
use App\DiscriminatedData\HolderData;
use App\DiscriminatedData\PetData;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for discriminator-aware oneOf/anyOf unions (issue #38). A named
 * `Pet` schema is a oneOf of `Cat`/`Dog` with a `petType` discriminator. The
 * generator emits an abstract PetData (PropertyMorphableData + morph()) and two
 * variant classes. We require_once the GENERATED files, boot Testbench, and prove
 * via the generated classes that spatie/laravel-data both validates per variant
 * and hydrates the right concrete variant through the real Laravel validator.
 */
function bootDiscriminatedClasses(): void
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
                'mapping' => [
                    'cat' => '#/components/schemas/Cat',
                    'dog' => '#/components/schemas/Dog',
                ],
            ],
        ],
        'Cat' => [
            'type' => 'object',
            'required' => ['petType', 'meow'],
            'properties' => [
                'petType' => ['type' => 'string'],
                'meow' => ['type' => 'string'],
            ],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['petType', 'bark'],
            'properties' => [
                'petType' => ['type' => 'string'],
                'bark' => ['type' => 'string'],
            ],
        ],
        'Holder' => [
            'type' => 'object',
            'required' => ['pet'],
            'properties' => [
                'pet' => ['$ref' => '#/components/schemas/Pet'],
            ],
        ],
        // Standalone reuse: a holder of a single variant ($ref: Cat) must still
        // hydrate and validate CatData on its own, independent of the union.
        'CatHolder' => [
            'type' => 'object',
            'required' => ['cat'],
            'properties' => [
                'cat' => ['$ref' => '#/components/schemas/Cat'],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Discriminated', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\DiscriminatedData')))->generate($spec);

    $dir = sys_get_temp_dir().'/oal_discriminated_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // The base must be loaded before its variants (the variants extend it), so
    // sort with the base first. ksort keeps Cat before Dog before Pet, but a
    // variant requiring its parent at require_once time needs the parent already
    // declared, so load PetData first explicitly.
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

beforeEach(fn () => bootDiscriminatedClasses());

it('hydrates a cat payload into the CatData variant', function () {
    $holder = HolderData::from(['pet' => ['petType' => 'cat', 'meow' => 'mrr']]);

    expect($holder->pet)->toBeInstanceOf(CatData::class);
    expect($holder->pet->meow)->toBe('mrr');
});

it('hydrates a dog payload into the DogData variant', function () {
    $holder = HolderData::from(['pet' => ['petType' => 'dog', 'bark' => 'woof']]);

    expect($holder->pet)->toBeInstanceOf(DogData::class);
});

it('rejects a cat discriminator carrying a dog shape (per-variant: meow required)', function () {
    HolderData::validate(['pet' => ['petType' => 'cat', 'bark' => 'woof']]);
})->throws(ValidationException::class);

it('rejects an unmapped discriminator value', function () {
    HolderData::validate(['pet' => ['petType' => 'unknown']]);
})->throws(ValidationException::class);

// Regression for #124: an unknown discriminator value on a TOP-LEVEL union (the
// abstract base consumed directly, e.g. as a request body) must surface a 422,
// not the uncatchable CannotCreateAbstractClass a 500. The validate() path runs
// the morph guard, but the creation paths (from / validateAndCreate / container
// injection) resolve the morph class BEFORE validation, so the morph() default
// arm now throws a ValidationException to make every path reject cleanly.
it('rejects an unknown discriminator value on the base via validate() (#124)', function () {
    PetData::validate(['petType' => 'unknown']);
})->throws(ValidationException::class);

it('rejects an unknown discriminator value on the base via from() (#124)', function () {
    PetData::from(['petType' => 'unknown']);
})->throws(ValidationException::class);

it('rejects an unknown discriminator value on the base via validateAndCreate() (#124)', function () {
    PetData::validateAndCreate(['petType' => 'unknown']);
})->throws(ValidationException::class);

it('rejects an unknown discriminator value on a NESTED union via from() (#124)', function () {
    // The same morph-then-validate creation path applies one level down, so a
    // hydrating from() on the holder must also reject rather than 500.
    HolderData::from(['pet' => ['petType' => 'unknown']]);
})->throws(ValidationException::class);

it('still hydrates a valid variant on the base via from() after the #124 fix', function () {
    $pet = PetData::from(['petType' => 'cat', 'meow' => 'mrr']);

    expect($pet)->toBeInstanceOf(CatData::class);
    expect($pet->meow)->toBe('mrr');
});

it('rejects a payload missing the discriminator property', function () {
    HolderData::validate(['pet' => ['meow' => 'mrr']]);
})->throws(ValidationException::class);

it('accepts a valid cat payload', function () {
    $validated = HolderData::validate(['pet' => ['petType' => 'cat', 'meow' => 'mrr']]);

    expect($validated)->toBeArray();
});

it('reuses a variant standalone via a $ref to it', function () {
    $holder = CatHolderData::from(['cat' => ['petType' => 'cat', 'meow' => 'purr']]);

    expect($holder->cat)->toBeInstanceOf(CatData::class);
    expect($holder->cat->meow)->toBe('purr');

    $validated = CatHolderData::validate(['cat' => ['petType' => 'cat', 'meow' => 'purr']]);
    expect($validated)->toBeArray();

    expect(fn () => CatHolderData::validate(['cat' => ['petType' => 'cat']]))
        ->toThrow(ValidationException::class);
});
