<?php

declare(strict_types=1);

use App\NullableMixedData\BoxData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for the #8 fix: a required oneOf/anyOf that includes a
 * `type: null` member (here after a messy `type: object` member) resolves to a
 * nullable `mixed` and emits `present` + `nullable` rules. A present null is
 * spec-valid and must be ACCEPTED, while a genuinely missing required key must
 * still be REJECTED. Proven through the real Laravel validator via validate().
 */
function bootNullableMixedClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        'Box' => [
            'type' => 'object',
            'required' => ['value'],
            'properties' => [
                'value' => ['oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                    ['type' => 'object'],
                    ['type' => 'null'],
                ]],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'NullableMixed', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\NullableMixedData')))->generate($spec);

    $dir = sys_get_temp_dir().'/oal_nullable_mixed_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ($files as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }

    $booted = true;
}

beforeEach(fn () => bootNullableMixedClasses());

it('accepts a present null on a required nullable mixed union (#8)', function () {
    $validated = BoxData::validate(['value' => null]);

    expect($validated)->toBeArray();
});

it('accepts a present scalar on a required nullable mixed union', function () {
    expect(BoxData::validate(['value' => 'x']))->toBeArray();
    expect(BoxData::validate(['value' => 7]))->toBeArray();
});

it('rejects a genuinely missing required key on the nullable mixed union (#8)', function () {
    BoxData::validate([]);
})->throws(ValidationException::class);

it('hydrates a present null without losing it', function () {
    $box = BoxData::from(['value' => null]);

    expect($box->value)->toBeNull();
    expect($box->toArray())->toHaveKey('value');
    expect($box->toArray()['value'])->toBeNull();
});
