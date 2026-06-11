<?php

declare(strict_types=1);

use App\IntDiscriminatedData\CircleData;
use App\IntDiscriminatedData\ShapeHolderData;
use App\IntDiscriminatedData\SquareData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for an INTEGER-typed discriminator (issue #38, the int variant
 * of the type-mismatch fix). `Shape` is a oneOf of `Circle`/`Square` whose
 * discriminator `kind` is an integer with a numeric mapping (1 -> Circle, 2 ->
 * Square). Proves: the variant forwards `int $kind` (no string/int mismatch
 * TypeError under strict_types), the morph() match arms are emitted as INT
 * literals so a strict match against the int payload compares equal, hydration
 * picks the right variant, and per-variant + unmapped validation hold.
 */
function bootIntDiscriminatedClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        'Shape' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Circle'],
                ['$ref' => '#/components/schemas/Square'],
            ],
            'discriminator' => [
                'propertyName' => 'kind',
                'mapping' => [
                    '1' => '#/components/schemas/Circle',
                    '2' => '#/components/schemas/Square',
                ],
            ],
        ],
        'Circle' => [
            'type' => 'object',
            'required' => ['kind', 'radius'],
            'properties' => [
                'kind' => ['type' => 'integer'],
                'radius' => ['type' => 'number'],
            ],
        ],
        'Square' => [
            'type' => 'object',
            'required' => ['kind', 'side'],
            'properties' => [
                'kind' => ['type' => 'integer'],
                'side' => ['type' => 'number'],
            ],
        ],
        'ShapeHolder' => [
            'type' => 'object',
            'required' => ['shape'],
            'properties' => [
                'shape' => ['$ref' => '#/components/schemas/Shape'],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'IntDiscriminated', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\IntDiscriminatedData')))->generate($spec);

    // The forwarded discriminator and the morph arms must be int-typed.
    expect($files['CircleData']->code)->toContain('int $kind,')
        ->and($files['CircleData']->code)->not->toContain('string $kind');
    expect($files['ShapeData']->code)->toContain('1 => CircleData::class,')
        ->and($files['ShapeData']->code)->toContain('2 => SquareData::class,')
        ->and($files['ShapeData']->code)->not->toContain("'1' =>");

    // Every generated file must compile.
    foreach ($files as $file) {
        token_get_all($file->code, TOKEN_PARSE);
    }

    $dir = sys_get_temp_dir().'/oal_int_discriminated_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $ordered = [];
    foreach ($files as $name => $file) {
        if ($name === 'ShapeData') {
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

beforeEach(fn () => bootIntDiscriminatedClasses());

it('hydrates an integer-discriminated payload into the right variant', function () {
    $holder = ShapeHolderData::from(['shape' => ['kind' => 1, 'radius' => 2.5]]);

    expect($holder->shape)->toBeInstanceOf(CircleData::class);
    expect($holder->shape->kind)->toBe(1);
    expect($holder->shape->radius)->toBe(2.5);
});

it('hydrates the second integer variant', function () {
    $holder = ShapeHolderData::from(['shape' => ['kind' => 2, 'side' => 4.0]]);

    expect($holder->shape)->toBeInstanceOf(SquareData::class);
    expect($holder->shape->kind)->toBe(2);
});

it('constructs a variant directly without a type error on the forwarded discriminator', function () {
    // Under strict_types a hardcoded string forwarded param would TypeError here.
    $circle = new CircleData(1, 2.5);

    expect($circle->kind)->toBe(1);
    expect($circle->radius)->toBe(2.5);
});

it('accepts a valid integer-discriminated payload', function () {
    $validated = ShapeHolderData::validate(['shape' => ['kind' => 1, 'radius' => 2.5]]);

    expect($validated)->toBeArray();
});

it('rejects an integer discriminator carrying the wrong variant shape', function () {
    // kind 1 is Circle, which requires `radius`; a Square `side` does not satisfy it.
    ShapeHolderData::validate(['shape' => ['kind' => 1, 'side' => 4.0]]);
})->throws(ValidationException::class);

it('rejects an unmapped integer discriminator value', function () {
    ShapeHolderData::validate(['shape' => ['kind' => 99]]);
})->throws(ValidationException::class);

it('rejects a payload missing the integer discriminator', function () {
    ShapeHolderData::validate(['shape' => ['radius' => 2.5]]);
})->throws(ValidationException::class);
