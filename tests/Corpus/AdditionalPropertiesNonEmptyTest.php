<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Regression guard for additionalProperties (map-style schemas). Before maps
 * were represented, a pure-map component (`type: object` with only
 * `additionalProperties`) emitted an empty Data class, and a property whose
 * value was such a map dropped the key/value shape entirely.
 *
 * Now a pure-map component is no longer emitted as a class at all: a `$ref` to
 * it inlines the typed array (`array<string, X>`) at the use site. These curated
 * corpus cases assert both halves: the empty pure-map class is gone, and the
 * referencing class carries the inlined map docblock.
 *
 * @return array<string, list<array{spec: string, goneClass: string, carrier: string, mapDoc: string}>>
 */
function additionalPropertiesExpectations(): array
{
    return [
        // apisguru: `APIs` is a pure $ref-valued map; `API.versions` references
        // the per-version map (`array<string, ApiVersionData>`).
        'apisguru.json' => [
            ['spec' => 'apisguru.json', 'goneClass' => 'APIsData', 'carrier' => 'APIData', 'mapDoc' => '@var array<string, ApiVersionData>'],
        ],
        // box: the string filter pure-map is gone; `MetadataQuery` carries a
        // scalar string map (`array<string, string>`).
        'box.json' => [
            ['spec' => 'box.json', 'goneClass' => 'MetadataFieldFilterStringData', 'carrier' => 'MetadataQueryData', 'mapDoc' => '@var array<string, string>'],
        ],
    ];
}

it('represents additionalProperties maps and drops the previously-empty map classes', function (string $spec, array $cases) {
    $document = (new SpecParser)->parseFileToDocument(__DIR__.'/../Fixtures/specs/'.$spec);
    $files = (new ModelGenerator)->generate($document);

    foreach ($cases as $case) {
        // The pure-map component no longer emits an (empty) Data class.
        expect(array_keys($files))->not->toContain($case['goneClass']);

        // The class that uses the map carries the inlined typed-array docblock.
        expect(array_keys($files))->toContain($case['carrier']);
        expect($files[$case['carrier']]->code)->toContain($case['mapDoc']);
    }
})->with(array_map(
    fn (string $spec, array $cases): array => [$spec, $cases],
    array_keys(additionalPropertiesExpectations()),
    array_values(additionalPropertiesExpectations()),
));
