<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Opt-in `additionalProperties: false` enforcement (issue #30). The closed-object
 * rule is emitted only when the `enforceClosedObjects` generator option is on.
 * With it off (the default) the output is exactly today's lenient output.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateClosedSchemas(array $schemas, bool $enforce): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $options = new GeneratorOptions('App\\Data', 'Data', 64, $enforce);

    return (new ModelGenerator($options))->generate($spec);
}

const CLOSED_SCHEMA = [
    'Closed' => [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['known'],
        'properties' => [
            'known' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
        ],
    ],
];

it('emits the closed-object rule when enforcement is opted in', function () {
    $code = generateClosedSchemas(CLOSED_SCHEMA, enforce: true)['ClosedData']->code;

    expect($code)
        ->toContain('use CodeWithAgents\OpenApiLaravel\Support\NoUnknownPropertiesRule;')
        ->toContain("'__openapi_laravel_no_unknown_properties' => [new NoUnknownPropertiesRule(['known', 'count'])]");
});

it('does not emit the closed-object rule when enforcement is off (default)', function () {
    $code = generateClosedSchemas(CLOSED_SCHEMA, enforce: false)['ClosedData']->code;

    expect($code)
        ->not->toContain('NoUnknownPropertiesRule')
        ->not->toContain('__openapi_laravel_no_unknown_properties');
});

it('keeps default-option output byte-identical to enforcement-off output', function () {
    // The default constructor (no flag) and an explicit off flag must produce
    // the same bytes: enforcement is purely additive and gated.
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => CLOSED_SCHEMA],
    ];
    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);

    $defaultCode = (new ModelGenerator)->generate($spec)['ClosedData']->code;
    $offCode = (new ModelGenerator(new GeneratorOptions('App\\Data', 'Data', 64, false)))->generate($spec)['ClosedData']->code;

    expect($defaultCode)->toBe($offCode);
});

it('does not emit the rule for an open object even with enforcement on', function () {
    // additionalProperties is not declared false, so the object is open: no rule.
    $code = generateClosedSchemas([
        'Open' => [
            'type' => 'object',
            'required' => ['known'],
            'properties' => ['known' => ['type' => 'string']],
        ],
    ], enforce: true)['OpenData']->code;

    expect($code)->not->toContain('NoUnknownPropertiesRule');
});

it('emits an empty allow-list for a pure-map closed object with no named properties', function () {
    // additionalProperties: false on an object with no named properties is a
    // contradiction in the spec (no key may appear); the rule allows nothing.
    $files = generateClosedSchemas([
        'Sealed' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => new stdClass,
        ],
    ], enforce: true);

    $code = $files['SealedData']->code;

    expect($code)->toContain('new NoUnknownPropertiesRule([])');
});

it('single-quote-escapes an untrusted wire name in the allow-list', function () {
    $code = generateClosedSchemas([
        'Tricky' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                "it's" => ['type' => 'string'],
            ],
        ],
    ], enforce: true)['TrickyData']->code;

    // The apostrophe is escaped so the emitted literal is valid, injection-safe PHP.
    expect($code)->toContain("new NoUnknownPropertiesRule(['it\\'s'])");
});
