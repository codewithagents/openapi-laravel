<?php

declare(strict_types=1);

use App\ConstraintData\DatedData;
use App\ConstraintData\HostedData;
use App\ConstraintData\NestedData;
use App\ConstraintData\NumberedData;
use App\ConstraintData\PetHolderData;
use App\ConstraintData\TimedData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for the validation-constraint fixes: generate Data classes
 * into a real namespace, load them into the booted Testbench app, and run the
 * generated rules() through the actual Laravel validator. This proves the rules
 * are not just the right strings but accept/reject the right payloads at runtime,
 * which is the only way to verify the RFC3339 date-time rule and MultipleOfRule
 * behave correctly.
 */
function bootConstraintClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        'Dated' => [
            'type' => 'object',
            'properties' => [
                'd' => ['type' => 'string', 'format' => 'date'],
                'ts' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
        'Numbered' => [
            'type' => 'object',
            'properties' => [
                'm' => ['type' => 'integer', 'multipleOf' => 3],
                'gt' => ['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => true],
                'ratio' => ['type' => 'number', 'enum' => [1.5, 2.5]],
                'tags' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string']],
            ],
        ],
        'Hosted' => [
            'type' => 'object',
            'properties' => [
                // format: hostname must enforce RFC1123 syntax (issue #29).
                'host' => ['type' => 'string', 'format' => 'hostname'],
                // format: idn-hostname keeps the softer non-whitespace rule.
                'idn' => ['type' => 'string', 'format' => 'idn-hostname'],
            ],
        ],
        'Nested' => [
            'type' => 'object',
            'properties' => [
                // array of array of integer (minimum 0): the inner item rules
                // must survive to depth (issue #28).
                'matrix' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ],
            ],
        ],
        'Timed' => [
            'type' => 'object',
            'properties' => [
                // format: time must enforce RFC3339 full-time (issue #49).
                't' => ['type' => 'string', 'format' => 'time'],
                // format: duration must enforce ISO 8601 duration (issue #49).
                'dur' => ['type' => 'string', 'format' => 'duration'],
            ],
        ],
        // Undiscriminated object union (issue #31): Cat is the FIRST variant,
        // Dog the second. A native `CatData|DogData` property type would make
        // laravel-data validate every payload against Cat, false-rejecting a
        // valid Dog. The interim fix types `pet` as `mixed` (presence-only) so
        // both variants are accepted.
        'Cat' => [
            'type' => 'object',
            'required' => ['meow'],
            'properties' => ['meow' => ['type' => 'string']],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['bark'],
            'properties' => ['bark' => ['type' => 'string']],
        ],
        'PetHolder' => [
            'type' => 'object',
            'required' => ['pet'],
            'properties' => [
                'pet' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    ['$ref' => '#/components/schemas/Dog'],
                ]],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Constraints', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator(new GeneratorOptions('App\\ConstraintData'));
    $files = $generator->generate($spec);
    // The generated Data classes import their rules from the consumer's own
    // Support namespace (issue #40), so the inlined support classes must be
    // written and loaded too or the rule references would be undefined at runtime.
    $supportFiles = $generator->supportFiles();

    $dir = sys_get_temp_dir().'/oal_constraints_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ([...array_values($files), ...array_values($supportFiles)] as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }

    $booted = true;
}

beforeEach(fn () => bootConstraintClasses());

it('accepts a Y-m-d date and rejects a timestamp for format date', function () {
    DatedData::validate(['d' => '2024-01-15']);
    expect(true)->toBeTrue();
});

it('rejects a date-time value on a date-only field', function () {
    DatedData::validate(['d' => '2024-01-15T10:30:00Z']);
})->throws(ValidationException::class);

it('accepts the three RFC3339 date-time forms', function () {
    foreach (['2024-01-15T10:30:00Z', '2024-01-15T10:30:00+02:00', '2024-01-15T10:30:00.123Z'] as $value) {
        DatedData::validate(['ts' => $value]);
    }
    expect(true)->toBeTrue();
});

it('rejects a bare date on a date-time field', function () {
    DatedData::validate(['ts' => '2024-01-15']);
})->throws(ValidationException::class);

it('rejects free text on a date-time field', function () {
    DatedData::validate(['ts' => 'not a date']);
})->throws(ValidationException::class);

it('accepts every RFC3339 time spelling for format time (#49)', function () {
    foreach (['14:30:00Z', '14:30:00+02:00', '14:30:00.123Z', '14:30:00', '23:59:59.999999-05:00'] as $value) {
        TimedData::validate(['t' => $value]);
    }
    expect(true)->toBeTrue();
});

it('rejects an out-of-range time for format time (#49)', function () {
    TimedData::validate(['t' => '25:00:00']);
})->throws(ValidationException::class);

it('rejects free text for format time (#49)', function () {
    TimedData::validate(['t' => 'noon']);
})->throws(ValidationException::class);

it('rejects a full date-time on a time-only field (#49)', function () {
    TimedData::validate(['t' => '2024-01-15T14:30:00Z']);
})->throws(ValidationException::class);

it('accepts valid ISO 8601 durations for format duration (#49)', function () {
    foreach (['P3Y6M4DT12H30M5S', 'PT1H', 'P1D', 'P1Y2M', 'PT0S'] as $value) {
        TimedData::validate(['dur' => $value]);
    }
    expect(true)->toBeTrue();
});

it('rejects a bare P with no components for format duration (#49)', function () {
    TimedData::validate(['dur' => 'P']);
})->throws(ValidationException::class);

it('rejects free text for format duration (#49)', function () {
    TimedData::validate(['dur' => 'noon']);
})->throws(ValidationException::class);

it('accepts a multiple and rejects a non-multiple for multipleOf', function () {
    NumberedData::validate(['m' => 9]);
    expect(true)->toBeTrue();
});

it('rejects a value that is not a multiple of the divisor', function () {
    NumberedData::validate(['m' => 7]);
})->throws(ValidationException::class);

it('rejects a value equal to an exclusive minimum', function () {
    NumberedData::validate(['gt' => 1]);
})->throws(ValidationException::class);

it('accepts a value above an exclusive minimum', function () {
    NumberedData::validate(['gt' => 2]);
    expect(true)->toBeTrue();
});

it('accepts an allowed float enum value and rejects an unlisted one', function () {
    NumberedData::validate(['ratio' => 1.5]);
    expect(true)->toBeTrue();
});

it('rejects a float outside the enum', function () {
    NumberedData::validate(['ratio' => 3.5]);
})->throws(ValidationException::class);

it('rejects duplicate items for uniqueItems', function () {
    NumberedData::validate(['tags' => ['a', 'a']]);
})->throws(ValidationException::class);

it('accepts distinct items for uniqueItems', function () {
    NumberedData::validate(['tags' => ['a', 'b']]);
    expect(true)->toBeTrue();
});

it('accepts valid hostnames for format hostname (#29)', function () {
    foreach (['example.com', 'api.service.io', 'localhost'] as $value) {
        HostedData::validate(['host' => $value]);
    }
    expect(true)->toBeTrue();
});

it('rejects a hostname with spaces (#29)', function () {
    HostedData::validate(['host' => 'not a hostname']);
})->throws(ValidationException::class);

it('rejects a hostname with illegal characters (#29)', function () {
    HostedData::validate(['host' => 'bad_host!']);
})->throws(ValidationException::class);

it('rejects a hostname with a leading-hyphen label (#29)', function () {
    HostedData::validate(['host' => '-bad.com']);
})->throws(ValidationException::class);

it('accepts a unicode idn-hostname and rejects whitespace (#29)', function () {
    HostedData::validate(['idn' => 'bücher.example']);
    expect(true)->toBeTrue();
});

it('rejects an idn-hostname with whitespace (#29)', function () {
    HostedData::validate(['idn' => 'bad host']);
})->throws(ValidationException::class);

it('accepts a valid nested array of arrays (#28)', function () {
    NestedData::validate(['matrix' => [[0, 1], [2, 3]]]);
    expect(true)->toBeTrue();
});

it('rejects an inner value below the minimum in a nested array (#28)', function () {
    NestedData::validate(['matrix' => [[1], [-1]]]);
})->throws(ValidationException::class);

it('rejects a non-array inner element in a nested array (#28)', function () {
    NestedData::validate(['matrix' => ['not-an-array']]);
})->throws(ValidationException::class);

it('accepts the first variant of an undiscriminated object union (Cat) (#31)', function () {
    PetHolderData::validate(['pet' => ['meow' => 'mrr']]);
    expect(true)->toBeTrue();
});

it('accepts a non-first variant of an undiscriminated object union without false-reject (Dog) (#31)', function () {
    // Before the interim fix this was false-rejected with "pet.meow is required"
    // because laravel-data validated the Dog payload against the first variant
    // (Cat). Presence-only typing accepts every valid variant.
    PetHolderData::validate(['pet' => ['bark' => 'woof']]);
    expect(true)->toBeTrue();
});
