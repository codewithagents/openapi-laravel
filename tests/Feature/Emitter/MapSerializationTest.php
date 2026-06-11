<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * End-to-end proof of the empty-map encoding fix: a generated map property
 * (`array<string, X>`) must serialize an EMPTY map as a JSON object `{}`, not the
 * array `[]` that `json_encode([])` emits. The fix ships a MapObjectTransformer
 * attached via `#[WithTransformer(...)]`; this loads the emitted class into the
 * booted app and asserts the wire shape across empty, non-empty, and null.
 */
function loadMapHolder(): string
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => [
            'schemas' => [
                'MapHolder' => [
                    'type' => 'object',
                    'required' => ['counts'],
                    'properties' => [
                        // A required scalar-value map.
                        'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                        // An optional map that may be left null.
                        'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $files = $generator->generate($spec);
    // The map property uses MapObjectTransformer, imported from the consumer's
    // own Support namespace (issue #40), so the inlined support class must be
    // written and loaded too or the transformer reference would be undefined.
    $supportFiles = $generator->supportFiles();

    $dir = sys_get_temp_dir().'/oal_mapser_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ([...array_values($files), ...array_values($supportFiles)] as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }

    return 'App\\Data\\MapHolderData';
}

it('serialises an empty map as a JSON object {} not an array []', function () {
    $class = loadMapHolder();

    $json = $class::from(['counts' => []])->toJson();

    // The empty map is encoded as an object, never the array form.
    expect($json)->toContain('"counts":{}')
        ->and($json)->not->toContain('"counts":[]');
});

it('serialises a non-empty map as an object with the same entries', function () {
    $class = loadMapHolder();

    $instance = $class::from(['counts' => ['a' => 1, 'b' => 2]]);

    expect($instance->counts)->toBe(['a' => 1, 'b' => 2]);

    $json = $instance->toJson();

    // Decoding round-trips to the exact key/value pairs as a JSON object.
    $decoded = json_decode($json, true);
    expect($decoded['counts'])->toBe(['a' => 1, 'b' => 2])
        ->and($json)->toContain('"counts":{"a":1,"b":2}');
});

it('leaves a null map as null', function () {
    $class = loadMapHolder();

    $json = $class::from(['counts' => []])->toJson();

    // The optional `labels` map was never provided, so it stays null.
    $decoded = json_decode($json, true);
    expect($decoded)->toHaveKey('labels')
        ->and($decoded['labels'])->toBeNull()
        ->and($json)->toContain('"labels":null');
});
