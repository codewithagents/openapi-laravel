<?php

declare(strict_types=1);

use App\DiscFormsData\CarData;
use App\DiscFormsData\InlineShapeCircleData;
use App\DiscFormsData\InlineShapeHolderData;
use App\DiscFormsData\InlineShapeSquareData;
use App\DiscFormsData\TruckData;
use App\DiscFormsData\VehicleHolderData;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for the two remaining discriminated-union forms (#38): the
 * INLINE-UNION form (a oneOf + discriminator with inline object members, no
 * named variant component) and the allOf-INHERITANCE form (a base object that
 * declares the discriminator, variants composing it via allOf). Both must morph
 * to the CORRECT concrete subclass and validate the selected variant's own
 * rules, exactly like the already-shipped named-component form.
 */
function bootDiscFormsClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        // Inline-union form: members are inline object schemas, each pinning its
        // discriminator via const; the mapping points into the oneOf by index.
        'InlineShape' => [
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['shapeKind', 'radius'],
                    'properties' => [
                        'shapeKind' => ['type' => 'string', 'const' => 'circle'],
                        'radius' => ['type' => 'number'],
                    ],
                ],
                [
                    'type' => 'object',
                    'required' => ['shapeKind', 'side'],
                    'properties' => [
                        'shapeKind' => ['type' => 'string', 'const' => 'square'],
                        'side' => ['type' => 'number'],
                    ],
                ],
            ],
            'discriminator' => [
                'propertyName' => 'shapeKind',
                'mapping' => [
                    'circle' => '#/components/schemas/InlineShape/oneOf/0',
                    'square' => '#/components/schemas/InlineShape/oneOf/1',
                ],
            ],
        ],
        'InlineShapeHolder' => [
            'type' => 'object',
            'required' => ['shape'],
            'properties' => ['shape' => ['$ref' => '#/components/schemas/InlineShape']],
        ],
        // allOf-inheritance form: Vehicle declares the discriminator; Car/Truck
        // compose it via allOf.
        'Vehicle' => [
            'type' => 'object',
            'required' => ['vehicleType'],
            'properties' => [
                'vehicleType' => ['type' => 'string'],
                'wheels' => ['type' => 'integer'],
            ],
            'discriminator' => [
                'propertyName' => 'vehicleType',
                'mapping' => ['car' => 'Car', 'truck' => 'Truck'],
            ],
        ],
        'Car' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Vehicle'],
                ['type' => 'object', 'required' => ['doors'], 'properties' => ['doors' => ['type' => 'integer']]],
            ],
        ],
        'Truck' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Vehicle'],
                ['type' => 'object', 'required' => ['payloadKg'], 'properties' => ['payloadKg' => ['type' => 'number']]],
            ],
        ],
        'VehicleHolder' => [
            'type' => 'object',
            'required' => ['vehicle'],
            'properties' => ['vehicle' => ['$ref' => '#/components/schemas/Vehicle']],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'DiscForms', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\DiscFormsData')))->generate($spec);

    $dir = sys_get_temp_dir().'/oal_disc_forms_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Abstract morphable bases must be declared before the variants extend them.
    $ordered = [];
    $rest = [];
    foreach ($files as $name => $file) {
        if (str_contains($file->code, 'abstract class ')) {
            $ordered[$name] = $file;
        } else {
            $rest[$name] = $file;
        }
    }
    $ordered += $rest;

    foreach ($ordered as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }

    $booted = true;
}

beforeEach(fn () => bootDiscFormsClasses());

// --- Inline-union form (#38) ----------------------------------------------

it('morphs the inline-union form to the correct synthesized variant subclass', function () {
    $circle = InlineShapeHolderData::from(['shape' => ['shapeKind' => 'circle', 'radius' => 1.5]]);
    expect($circle->shape)->toBeInstanceOf(InlineShapeCircleData::class);
    expect($circle->shape->radius)->toBe(1.5);

    $square = InlineShapeHolderData::from(['shape' => ['shapeKind' => 'square', 'side' => 2.0]]);
    expect($square->shape)->toBeInstanceOf(InlineShapeSquareData::class);
    expect($square->shape->side)->toBe(2.0);
});

it('validates per variant through the inline-union base', function () {
    expect(InlineShapeHolderData::validate(['shape' => ['shapeKind' => 'circle', 'radius' => 1.5]]))->toBeArray();

    // circle discriminator but missing the circle-required radius is rejected.
    expect(fn () => InlineShapeHolderData::validate(['shape' => ['shapeKind' => 'circle']]))
        ->toThrow(ValidationException::class);
    // unmapped discriminator value is rejected (morph yields null).
    expect(fn () => InlineShapeHolderData::validate(['shape' => ['shapeKind' => 'triangle']]))
        ->toThrow(ValidationException::class);
    // missing the discriminator is rejected.
    expect(fn () => InlineShapeHolderData::validate(['shape' => ['radius' => 1.0]]))
        ->toThrow(ValidationException::class);
});

// --- allOf-inheritance form (#38) -----------------------------------------

it('morphs the allOf-inheritance form to the correct variant subclass', function () {
    $car = VehicleHolderData::from(['vehicle' => ['vehicleType' => 'car', 'doors' => 4, 'wheels' => 4]]);
    expect($car->vehicle)->toBeInstanceOf(CarData::class);
    expect($car->vehicle->doors)->toBe(4);
    // A base-shared optional property is carried on the variant.
    expect($car->vehicle->wheels)->toBe(4);

    $truck = VehicleHolderData::from(['vehicle' => ['vehicleType' => 'truck', 'payloadKg' => 1200.0]]);
    expect($truck->vehicle)->toBeInstanceOf(TruckData::class);
    expect($truck->vehicle->payloadKg)->toBe(1200.0);
});

it('validates per variant through the allOf-inheritance base', function () {
    expect(VehicleHolderData::validate(['vehicle' => ['vehicleType' => 'car', 'doors' => 4]]))->toBeArray();

    // car discriminator but missing the car-required doors is rejected.
    expect(fn () => VehicleHolderData::validate(['vehicle' => ['vehicleType' => 'car']]))
        ->toThrow(ValidationException::class);
    // unmapped discriminator value is rejected.
    expect(fn () => VehicleHolderData::validate(['vehicle' => ['vehicleType' => 'boat']]))
        ->toThrow(ValidationException::class);
    // missing the discriminator is rejected.
    expect(fn () => VehicleHolderData::validate(['vehicle' => ['doors' => 4]]))
        ->toThrow(ValidationException::class);
});
