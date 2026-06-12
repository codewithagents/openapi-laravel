<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * `additionalProperties: false` enforcement (issue #30). The closed-object rule
 * is emitted whenever the `enforceClosedObjects` generator option is on, which
 * is the default. Opting out (enforceClosedObjects: false) restores the lenient
 * output with no rule.
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

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

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

it('emits the closed-object rule when enforcement is on', function () {
    $code = generateClosedSchemas(CLOSED_SCHEMA, enforce: true)['ClosedData']->code;

    expect($code)
        ->toContain('use App\Data\Support\NoUnknownPropertiesRule;')
        ->toContain("'__openapi_laravel_no_unknown_properties' => [new NoUnknownPropertiesRule(['known', 'count'])]");
});

it('does not emit the closed-object rule when enforcement is opted out', function () {
    $code = generateClosedSchemas(CLOSED_SCHEMA, enforce: false)['ClosedData']->code;

    expect($code)
        ->not->toContain('NoUnknownPropertiesRule')
        ->not->toContain('__openapi_laravel_no_unknown_properties');
});

it('keeps default-option output byte-identical to enforcement-on output', function () {
    // The default constructor (no flag) and an explicit on flag must produce the
    // same bytes: enforcement is on by default (#30), so the rule appears either
    // way and the closed shape is honored without any flag.
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => CLOSED_SCHEMA],
    ];
    $spec = (new OpenApiReader)->read($document);

    $defaultCode = (new ModelGenerator)->generate($spec)['ClosedData']->code;
    $onCode = (new ModelGenerator(new GeneratorOptions('App\\Data', 'Data', 64, true)))->generate($spec)['ClosedData']->code;

    expect($defaultCode)->toBe($onCode)
        ->and($defaultCode)->toContain('new NoUnknownPropertiesRule(');
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

it('allow-lists patternProperties patterns alongside the declared names (#65)', function () {
    // patternProperties legally admits matching keys even under
    // additionalProperties: false, so the rule carries the delimited patterns
    // as a second allow-list and a build warning surfaces the relaxation.
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Tagged' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string']],
                'patternProperties' => [
                    '^x-' => ['type' => 'string'],
                    '^meta_[a-z]+$' => ['type' => 'integer'],
                ],
            ],
        ]],
    ];
    $spec = (new OpenApiReader)->read($document);
    $generator = new ModelGenerator(new GeneratorOptions('App\\Data', 'Data', 64, true));
    $code = $generator->generate($spec)['TaggedData']->code;

    expect($code)
        ->toContain("new NoUnknownPropertiesRule(['name'], ['#^x-#', '#^meta_[a-z]+\$#'])")
        ->and($generator->warnings())->toContain(
            'Schema "Tagged" combines additionalProperties: false with patternProperties. '
            .'Keys matching a pattern are accepted by the closed-object rule, but their value schemas are not validated.',
        );
});

it('falls back to the next PCRE delimiter when a pattern contains the first (#65)', function () {
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Hashed' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['name' => ['type' => 'string']],
                'patternProperties' => ['^#\\d+$' => ['type' => 'string']],
            ],
        ]],
    ];
    $spec = (new OpenApiReader)->read($document);
    $code = (new ModelGenerator)->generate($spec)['HashedData']->code;

    // '#' appears in the pattern, so the delimiter falls through to '~',
    // mirroring the `pattern` rule's delimiter selection.
    expect($code)->toContain("new NoUnknownPropertiesRule(['name'], ['~^#\\\\d+\$~'])");
});

it('skips closed-object enforcement when a patternProperties pattern is not valid PCRE (#65)', function () {
    // An uncompilable pattern means the rule cannot tell legal keys apart, so
    // the sound fallback is no closed-object rule at all (under-validating
    // beats false-rejecting), surfaced by a build warning.
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Broken' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['name' => ['type' => 'string']],
                'patternProperties' => ['(' => ['type' => 'string']],
            ],
        ]],
    ];
    $spec = (new OpenApiReader)->read($document);
    $generator = new ModelGenerator(new GeneratorOptions('App\\Data', 'Data', 64, true));
    $code = $generator->generate($spec)['BrokenData']->code;

    expect($code)
        ->not->toContain('NoUnknownPropertiesRule')
        ->and($generator->warnings())->toContain(
            'Schema "Broken" declares patternProperties with a pattern that is not valid PCRE ("("); '
            .'closed-object enforcement (additionalProperties: false) is skipped for this schema '
            .'so spec-legal keys are never falsely rejected.',
        );
});

it('emits no pattern allow-list for patternProperties without additionalProperties: false (#65)', function () {
    // An open object never carries the closed-object rule, patterns or not.
    $code = generateClosedSchemas([
        'OpenPatterned' => [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'patternProperties' => ['^x-' => ['type' => 'string']],
        ],
    ], enforce: true)['OpenPatternedData']->code;

    expect($code)->not->toContain('NoUnknownPropertiesRule');
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
