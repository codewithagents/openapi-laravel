<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Fidelity\FidelityEntry;
use CodeWithAgents\OpenApiLaravel\Parser\Fidelity\FidelityReport;
use CodeWithAgents\OpenApiLaravel\Parser\Fidelity\FidelityScanner;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The fidelity report (the unsupported-construct artifact, openapi-laravel.unsupported.json):
 * the scanner walks the raw spec and records every construct the generator
 * cannot faithfully represent and that affects correctness or runtime behavior;
 * the report dedupes, sorts deterministically, and serializes to a byte-stable
 * JSON shape so the artifact passes the drift gate.
 */
$fixture = fn (): string => __DIR__.'/../../../Fixtures/fidelity/unsupported-constructs.yaml';
$supported = fn (): string => __DIR__.'/../../../Fixtures/fidelity/fully-supported.yaml';

it('serializes the exact JSON shape with a fixed generator name and a sorted entry list', function () {
    $report = new FidelityReport;
    $report->record('#/paths/~1pets/get/parameters/0', "GET /pets, query parameter 'tags'", 'repeated-key array query parameter (form, explode:true)', 'only the last value survives, array elements are silently lost');

    $json = $report->toJson();
    $decoded = json_decode($json, true);

    expect($decoded['generator'])->toBe('openapi-laravel')
        ->and($decoded['unsupported'])->toHaveCount(1)
        ->and($decoded['unsupported'][0])->toBe([
            'pointer' => '#/paths/~1pets/get/parameters/0',
            'location' => "GET /pets, query parameter 'tags'",
            'construct' => 'repeated-key array query parameter (form, explode:true)',
            'impact' => 'only the last value survives, array elements are silently lost',
            'severity' => 'correctness',
        ])
        // Pretty-printed with a trailing newline, matching the other artifacts.
        ->and($json)->toEndWith("}\n")
        ->and($json)->toContain('    "generator"');
});

it('emits an empty unsupported array (not a missing key) when nothing is unsupported', function () {
    $json = (new FidelityReport)->toJson();
    $decoded = json_decode($json, true);

    expect($decoded)->toBe(['generator' => 'openapi-laravel', 'unsupported' => []])
        ->and($json)->toContain('"unsupported": []');
});

it('dedupes identical entries on (pointer, construct)', function () {
    $report = new FidelityReport;
    $entry = new FidelityEntry('#/x', 'loc', 'construct', 'impact');
    $report->add($entry);
    $report->add($entry);
    $report->add(new FidelityEntry('#/x', 'loc', 'construct', 'impact'));

    expect($report->count())->toBe(1);
});

it('sorts entries deterministically by pointer then construct', function () {
    $report = new FidelityReport;
    $report->record('#/b', 'l', 'zeta', 'i');
    $report->record('#/a', 'l', 'beta', 'i');
    $report->record('#/a', 'l', 'alpha', 'i');

    $pointers = array_map(static fn (FidelityEntry $e): string => $e->pointer.'|'.$e->construct, $report->entries());

    expect($pointers)->toBe(['#/a|alpha', '#/a|beta', '#/b|zeta']);
});

it('is byte-identical across two scans of the same spec (determinism)', function () use ($fixture) {
    $document = (new SpecParser)->parseFileToDocument($fixture());

    $first = new FidelityReport;
    foreach ($document->fidelity as $entry) {
        $first->add($entry);
    }
    $second = new FidelityReport;
    foreach ((new SpecParser)->parseFileToDocument($fixture())->fidelity as $entry) {
        $second->add($entry);
    }

    expect($first->toJson())->toBe($second->toJson());
});

it('records every expected unsupported construct from the fixture (sorted, deduped)', function () use ($fixture) {
    $document = (new SpecParser)->parseFileToDocument($fixture());

    $constructs = array_map(static fn (FidelityEntry $e): string => $e->construct, $document->fidelity);

    expect($constructs)->toContain('repeated-key array query parameter (form, explode:true)')
        ->and($constructs)->toContain('cookie parameter')
        ->and($constructs)->toContain('success response headers')
        ->and($constructs)->toContain('operation callbacks')
        ->and($constructs)->toContain('style: matrix path parameter')
        ->and($constructs)->toContain('undiscriminated object oneOf')
        // The fixture's `forbidden: {not: {type: string}}` is a type exclusion,
        // the intractable not form, so it stays recorded after Stream 2.
        ->and($constructs)->toContain('not (forbidden-shape) keyword')
        ->and($constructs)->toContain('patternProperties value schemas')
        ->and($constructs)->toContain('$ref-valued additionalProperties map values')
        // int32/int64 are now emitted as range rules (Stream 2), so the fixture's
        // int64 field no longer appears in the report.
        ->and($constructs)->not->toContain('integer format: int64');

    // The matrix path parameter carries the precise RFC 6901 pointer (encoded
    // slashes and a literal brace segment), proving the per-segment encoding.
    $matrix = array_values(array_filter($document->fidelity, static fn (FidelityEntry $e): bool => $e->construct === 'style: matrix path parameter'));
    expect($matrix[0]->pointer)->toBe('#/paths/~1pets~1{petId}/get/parameters/0');
});

it('produces an empty report for a fully supported spec', function () use ($supported) {
    $document = (new SpecParser)->parseFileToDocument($supported());

    expect($document->fidelity)->toBe([]);

    $report = new FidelityReport;
    foreach ($document->fidelity as $entry) {
        $report->add($entry);
    }
    expect($report->isEmpty())->toBeTrue()
        ->and(json_decode($report->toJson(), true)['unsupported'])->toBe([]);
});

it('does not follow a $ref child, so a gap is recorded once at its definition', function () {
    // The fixture's Cat schema is fully supported; the $ref to it from
    // additionalProperties and oneOf must not pull a duplicate or a wrong-pointer
    // entry into Cat's territory. Only the map-value construct at the use site.
    $document = (new SpecParser)->parseFileToDocument(__DIR__.'/../../../Fixtures/fidelity/unsupported-constructs.yaml');

    $catPointers = array_filter($document->fidelity, static fn (FidelityEntry $e): bool => str_contains($e->pointer, '/schemas/Cat'));
    expect($catPointers)->toBe([]);
});

// Stream 2: int32/int64 and the tractable `not` subset are now SUPPORTED, so
// they must drop out of the fidelity report; the intractable `not` forms stay.

it('no longer records int32/int64 integer formats (now emitted as range rules)', function () {
    $spec = [
        'openapi' => '3.1.0',
        'info' => ['title' => 't', 'version' => '1'],
        'components' => ['schemas' => [
            'Widget' => [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'integer', 'format' => 'int32'],
                    'b' => ['type' => 'integer', 'format' => 'int64'],
                ],
            ],
        ]],
    ];

    $entries = (new FidelityScanner)->scan($spec);
    $constructs = array_map(static fn (FidelityEntry $e): string => $e->construct, $entries);

    expect($constructs)->not->toContain('integer format: int32')
        ->and($constructs)->not->toContain('integer format: int64')
        ->and($entries)->toBe([]);
});

it('does not record a supported not/enum or not/const, but records an intractable not', function () {
    $spec = [
        'openapi' => '3.1.0',
        'info' => ['title' => 't', 'version' => '1'],
        'components' => ['schemas' => [
            'Widget' => [
                'type' => 'object',
                'properties' => [
                    'supportedEnum' => ['type' => 'string', 'not' => ['enum' => ['a', 'b']]],
                    'supportedConst' => ['type' => 'string', 'not' => ['const' => 'z']],
                    'typeExclusion' => ['type' => 'string', 'not' => ['type' => 'number']],
                ],
            ],
        ]],
    ];

    $entries = (new FidelityScanner)->scan($spec);

    // Exactly one entry: the type-exclusion not, pointing at its own location.
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->construct)->toBe('not (forbidden-shape) keyword')
        ->and($entries[0]->pointer)->toBe('#/components/schemas/Widget/properties/typeExclusion/not');
});

it('records a not/const that the rules emitter cannot express (float const)', function () {
    $spec = [
        'openapi' => '3.1.0',
        'info' => ['title' => 't', 'version' => '1'],
        'components' => ['schemas' => [
            'Widget' => [
                'type' => 'object',
                'properties' => [
                    'floatConst' => ['type' => 'number', 'not' => ['const' => 1.5]],
                ],
            ],
        ]],
    ];

    $entries = (new FidelityScanner)->scan($spec);
    $constructs = array_map(static fn (FidelityEntry $e): string => $e->construct, $entries);

    expect($constructs)->toContain('not (forbidden-shape) keyword');
});

it('treats an empty not ({}) as a no-op and does not record it', function () {
    $spec = [
        'openapi' => '3.1.0',
        'info' => ['title' => 't', 'version' => '1'],
        'components' => ['schemas' => [
            'Widget' => ['type' => 'object', 'properties' => ['x' => ['not' => []]]],
        ]],
    ];

    expect((new FidelityScanner)->scan($spec))->toBe([]);
});
