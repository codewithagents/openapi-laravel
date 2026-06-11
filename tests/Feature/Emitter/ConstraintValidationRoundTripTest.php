<?php

declare(strict_types=1);

use App\ConstraintData\DatedData;
use App\ConstraintData\NestedData;
use App\ConstraintData\NumberedData;
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
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Constraints', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $files = (new ModelGenerator(new GeneratorOptions('App\\ConstraintData')))->generate($spec);

    $dir = sys_get_temp_dir().'/oal_constraints_'.getmypid();
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
