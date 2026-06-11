<?php

declare(strict_types=1);

use App\DiscriminatedArrayData\BagData;
use App\DiscriminatedArrayData\CarData;
use App\DiscriminatedArrayData\CatData;
use App\DiscriminatedArrayData\DogData;
use App\DiscriminatedArrayData\MappedHolderData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Two further behavioral proofs for discriminator-aware unions (issue #38):
 *
 *  - an ARRAY of a discriminated union: a `pets: array of $ref Pet`. The
 *    generator emits `#[DataCollectionOf(PetData::class)]`; this test pins
 *    whether spatie actually morphs EACH element to the right variant and
 *    validates per-variant, rather than assuming it.
 *  - a MapName discriminator (`pet_type` wire name -> `petType` PHP name): the
 *    morph() must read the PHP property name (spatie keys the morph payload by
 *    DataProperty->name even under #[MapName]), or hydration silently fails.
 */
function bootDiscriminatedArrayClasses(): void
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
        'Bag' => [
            'type' => 'object',
            'required' => ['pets'],
            'properties' => [
                'pets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']],
            ],
        ],
        // A separate discriminated union whose discriminator wire name (snake
        // case) differs from its PHP property name, forcing a #[MapName].
        'Vehicle' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Car'],
                ['$ref' => '#/components/schemas/Bike'],
            ],
            'discriminator' => [
                'propertyName' => 'vehicle_type',
                'mapping' => ['car' => 'Car', 'bike' => 'Bike'],
            ],
        ],
        'Car' => [
            'type' => 'object',
            'required' => ['vehicle_type', 'doors'],
            'properties' => [
                'vehicle_type' => ['type' => 'string'],
                'doors' => ['type' => 'integer'],
            ],
        ],
        'Bike' => [
            'type' => 'object',
            'required' => ['vehicle_type'],
            'properties' => [
                'vehicle_type' => ['type' => 'string'],
                'electric' => ['type' => 'boolean'],
            ],
        ],
        'MappedHolder' => [
            'type' => 'object',
            'required' => ['vehicle'],
            'properties' => [
                'vehicle' => ['$ref' => '#/components/schemas/Vehicle'],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'DiscriminatedArray', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\DiscriminatedArrayData')))->generate($spec);

    $dir = sys_get_temp_dir().'/oal_discriminated_array_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Load each base before its variants (a variant extends its base).
    $ordered = [];
    foreach ($files as $name => $file) {
        if ($name === 'PetData' || $name === 'VehicleData') {
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

beforeEach(fn () => bootDiscriminatedArrayClasses());

it('morphs each element of an array of a discriminated union to its variant', function () {
    $bag = BagData::from(['pets' => [
        ['petType' => 'cat', 'meow' => 'mrr'],
        ['petType' => 'dog', 'bark' => 'woof'],
    ]]);

    expect($bag->pets)->toHaveCount(2);
    expect($bag->pets[0])->toBeInstanceOf(CatData::class);
    expect($bag->pets[0]->meow)->toBe('mrr');
    expect($bag->pets[1])->toBeInstanceOf(DogData::class);
    expect($bag->pets[1]->bark)->toBe('woof');
});

it('validates each element of an array of a discriminated union per variant', function () {
    $validated = BagData::validate(['pets' => [
        ['petType' => 'cat', 'meow' => 'mrr'],
        ['petType' => 'dog', 'bark' => 'woof'],
    ]]);

    expect($validated)->toBeArray();
});

it('rejects an array element whose variant shape is wrong (cat without meow)', function () {
    BagData::validate(['pets' => [
        ['petType' => 'cat', 'bark' => 'woof'],
    ]]);
})->throws(ValidationException::class);

it('rejects an array element with an unmapped discriminator', function () {
    BagData::validate(['pets' => [
        ['petType' => 'fish'],
    ]]);
})->throws(ValidationException::class);

it('morphs a MapName discriminator by hydrating the right variant', function () {
    $holder = MappedHolderData::from(['vehicle' => ['vehicle_type' => 'car', 'doors' => 4]]);

    expect($holder->vehicle)->toBeInstanceOf(CarData::class);
    expect($holder->vehicle->doors)->toBe(4);
});

it('validates a MapName discriminator per variant', function () {
    $validated = MappedHolderData::validate(['vehicle' => ['vehicle_type' => 'car', 'doors' => 4]]);
    expect($validated)->toBeArray();

    // A car missing its required `doors` fails the variant rule.
    expect(fn () => MappedHolderData::validate(['vehicle' => ['vehicle_type' => 'car']]))
        ->toThrow(ValidationException::class);

    // An unmapped vehicle type is rejected by the morph guard.
    expect(fn () => MappedHolderData::validate(['vehicle' => ['vehicle_type' => 'plane']]))
        ->toThrow(ValidationException::class);
});
