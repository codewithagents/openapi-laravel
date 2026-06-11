<?php

declare(strict_types=1);

use App\ClosedStrict\AccountData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for opt-in `additionalProperties: false` enforcement (issue
 * #30). Generates the SAME closed schema twice, once with enforceClosedObjects
 * on and once off, into two distinct namespaces, loads both into the booted
 * Testbench app, and runs the generated rules() through the real Laravel
 * validator. Proves the emitted NoUnknownPropertiesRule actually rejects an
 * unknown key with the flag on, and that the default (off) output still accepts
 * it, mirroring tests/Feature/Emitter/ConstraintValidationRoundTripTest.php.
 */
function bootClosedObjectClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        'Account' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'label' => ['type' => 'string'],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Closed', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $base = sys_get_temp_dir().'/oal_closed_'.getmypid();

    // Strict variant: enforcement on, into App\ClosedStrict.
    $strict = (new ModelGenerator(new GeneratorOptions('App\\ClosedStrict', 'Data', 64, true)))->generate($spec);
    // Lenient variant (default behavior): enforcement off, into App\ClosedLenient.
    $lenient = (new ModelGenerator(new GeneratorOptions('App\\ClosedLenient', 'Data', 64, false)))->generate($spec);

    // Both variants emit AccountData.php, so write each into its own directory to
    // avoid the second require_once shadowing the first on a shared filename.
    foreach (['strict' => $strict, 'lenient' => $lenient] as $label => $files) {
        $dir = $base.'/'.$label;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach ($files as $file) {
            $path = $dir.'/'.$file->filename();
            file_put_contents($path, $file->code);
            require_once $path;
        }
    }

    $booted = true;
}

beforeEach(fn () => bootClosedObjectClasses());

it('accepts a payload with only declared keys when enforcement is on', function () {
    AccountData::validate(['id' => 1, 'label' => 'main']);
    expect(true)->toBeTrue();
});

it('rejects a payload with an unknown key when enforcement is on (#30)', function () {
    AccountData::validate(['id' => 1, 'extra' => 'nope']);
})->throws(ValidationException::class);

it('rejects an unknown key even with no declared optional key present (#30)', function () {
    AccountData::validate(['id' => 1, 'surprise' => true]);
})->throws(ValidationException::class);

it('accepts an unknown key when enforcement is off (default behavior, the documented gap)', function () {
    // This is exactly today's lenient default: the unknown key is silently
    // accepted. The known-gap ratchet documents this; enforcement is opt in.
    App\ClosedLenient\AccountData::validate(['id' => 1, 'extra' => 'accepted']);
    expect(true)->toBeTrue();
});
