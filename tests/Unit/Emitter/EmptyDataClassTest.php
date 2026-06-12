<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Empty generated Data classes are made visible (issue #95). A schema with no
 * properties produces a class whose body compiles fine but silently drops
 * every payload field; the gap was invisible until runtime. The body now
 * carries a marker comment and generation emits one warning per empty class
 * through the existing warnings channel, naming the schema.
 *
 * Intentionally NOT flagged: pure maps and non-object aliases (inlined at use
 * sites, no class at all), a closed empty object (it carries the closed-object
 * rule, so unknown fields are rejected rather than dropped), and a scalar enum
 * component (wrapped into a single `value` property).
 *
 * @param  array<string, mixed>  $schemas
 * @return array{0: array<string, GeneratedFile>, 1: list<string>}
 */
function generateWithWarnings(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $files = $generator->generate($spec);

    return [$files, $generator->warnings()];
}

const EMPTY_BODY_MARKER_TEXT = '// The spec defines no properties for this schema.';

it('emits the marker comment inside an empty Data class body', function () {
    [$files] = generateWithWarnings([
        'Blank' => ['type' => 'object'],
    ]);

    expect($files['BlankData']->code)
        ->toContain("final class BlankData extends Data\n{\n    ".EMPTY_BODY_MARKER_TEXT."\n}\n");
});

it('emits the marker for an explicit empty properties map too', function () {
    [$files] = generateWithWarnings([
        'BlankExplicit' => ['type' => 'object', 'properties' => new stdClass],
    ]);

    expect($files['BlankExplicitData']->code)->toContain(EMPTY_BODY_MARKER_TEXT);
});

it('warns once per empty schema, naming the schema and the class', function () {
    [, $warnings] = generateWithWarnings([
        'Blank' => ['type' => 'object'],
        'Filled' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ]);

    $emptyWarnings = array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'empty body')));

    expect($emptyWarnings)->toHaveCount(1)
        ->and($emptyWarnings[0])->toContain('"Blank"')
        ->and($emptyWarnings[0])->toContain('"BlankData"');
});

it('does not mark or warn for a class that has properties', function () {
    [$files, $warnings] = generateWithWarnings([
        'Filled' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ]);

    expect($files['FilledData']->code)->not->toContain(EMPTY_BODY_MARKER_TEXT)
        ->and($warnings)->toBe([]);
});

it('does not mark a closed empty object (it carries the closed-object rule instead)', function () {
    [$files, $warnings] = generateWithWarnings([
        'ClosedBlank' => ['type' => 'object', 'additionalProperties' => false],
    ]);

    // Enforcement is on by default: unknown fields are rejected, not dropped,
    // so the gap the marker exists for does not apply.
    expect($files['ClosedBlankData']->code)->toContain('NoUnknownPropertiesRule')
        ->and($files['ClosedBlankData']->code)->not->toContain(EMPTY_BODY_MARKER_TEXT)
        ->and($warnings)->toBe([]);
});

it('does not flag pure-map or alias components (no class is emitted for them at all)', function () {
    [$files, $warnings] = generateWithWarnings([
        'Language' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        'Tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'language' => ['$ref' => '#/components/schemas/Language'],
                'tags' => ['$ref' => '#/components/schemas/Tags'],
            ],
        ],
    ]);

    expect(array_keys($files))->toBe(['HolderData'])
        ->and($warnings)->toBe([]);
});

it('marks and warns for a write variant whose every property is readOnly', function () {
    [$files, $warnings] = generateWithWarnings([
        'Server' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'etag' => ['type' => 'string', 'readOnly' => true],
            ],
        ],
    ]);

    // The read variant keeps both properties; the write variant drops them all.
    expect($files['ServerData']->code)->not->toContain(EMPTY_BODY_MARKER_TEXT)
        ->and($files['ServerWritableData']->code)->toContain(EMPTY_BODY_MARKER_TEXT);

    $emptyWarnings = array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'empty body')));

    expect($emptyWarnings)->toHaveCount(1)
        ->and($emptyWarnings[0])->toContain('no writable (non-readOnly) properties')
        ->and($emptyWarnings[0])->toContain('"ServerWritableData"');
});

it('is deterministic: empty-class output and warnings are byte-identical across runs', function () {
    $schemas = [
        'Blank' => ['type' => 'object'],
        'Holder' => [
            'type' => 'object',
            'properties' => ['a' => ['$ref' => '#/components/schemas/Blank']],
        ],
    ];

    [$firstFiles, $firstWarnings] = generateWithWarnings($schemas);
    [$secondFiles, $secondWarnings] = generateWithWarnings($schemas);

    foreach ($firstFiles as $name => $file) {
        expect($secondFiles[$name]->code)->toBe($file->code);
    }
    expect($secondWarnings)->toBe($firstWarnings);
});
