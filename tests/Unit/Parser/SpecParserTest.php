<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

function writeTempSpec(string $name, string $contents): string
{
    $path = sys_get_temp_dir().'/openapi-laravel-test-'.uniqid().'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}

const MINIMAL_JSON = '{"openapi":"3.0.3","info":{"title":"T","version":"1.0.0"},"paths":{}}';

const MINIMAL_YAML = "openapi: 3.0.3\ninfo:\n  title: T\n  version: 1.0.0\npaths: {}\n";

it('parses a JSON document into the typed graph', function () {
    $path = writeTempSpec('spec.json', MINIMAL_JSON);

    $document = (new SpecParser)->parseFileToDocument($path);

    expect($document)->toBeInstanceOf(OpenApiDocument::class)
        ->and($document->info->title)->toBe('T');
});

it('parses a YAML document into the typed graph', function () {
    $path = writeTempSpec('spec.yaml', MINIMAL_YAML);

    expect((new SpecParser)->parseFileToDocument($path)->info->version)->toBe('1.0.0');
});

it('sniffs JSON content for an unknown extension', function () {
    $path = writeTempSpec('spec.txt', MINIMAL_JSON);

    expect((new SpecParser)->parseFileToDocument($path)->info->title)->toBe('T');
});

it('throws for a missing file', function () {
    (new SpecParser)->parseFileToDocument('/no/such/spec.json');
})->throws(ParseException::class, 'not found');

it('wraps malformed content in a ParseException naming the attempted format', function () {
    $path = writeTempSpec('broken.json', '{not valid json');

    expect(fn () => (new SpecParser)->parseFileToDocument($path))
        ->toThrow(ParseException::class, 'Failed to parse OpenAPI spec')
        ->toThrow(ParseException::class, 'as JSON')
        ->toThrow(ParseException::class, 'Check that the file is well-formed JSON');
});

// A-1: a non-OpenAPI-3.x document must fail loudly, not parse into a
// null-filled graph that silently produces nothing.

it('rejects a Swagger 2.0 document by version', function () {
    $path = writeTempSpec('swagger.json', '{"swagger":"2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    (new SpecParser)->parseFileToDocument($path);
})->throws(ParseException::class, 'Not an OpenAPI 3.x document');

it('rejects an OpenAPI 2.x version string', function () {
    $path = writeTempSpec('v2.json', '{"openapi":"2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    (new SpecParser)->parseFileToDocument($path);
})->throws(ParseException::class, 'Unsupported OpenAPI version');

it('rejects a document missing the info object', function () {
    $path = writeTempSpec('noinfo.json', '{"openapi":"3.0.3","paths":{}}');

    (new SpecParser)->parseFileToDocument($path);
})->throws(ParseException::class, "the required '#/info' object is missing");

it('rejects an empty file with the structural error, not a silent success', function () {
    $path = writeTempSpec('empty.yaml', '');

    (new SpecParser)->parseFileToDocument($path);
})->throws(ParseException::class, "the root '#/openapi' member must be a version string");

// #103: exact version gating instead of the old `3.` prefix check. 3.0.x and
// 3.1.x are fully supported (no warnings); 3.2.x is accepted best-effort with
// a loud warning plus one warning per dropped 3.2-only construct; anything
// else is rejected with an error naming the supported matrix.

it('accepts every supported version band without warnings', function (string $version) {
    $path = writeTempSpec('band.json', '{"openapi":"'.$version.'","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    $parser = new SpecParser;

    expect($parser->parseFileToDocument($path)->openapi)->toBe($version)
        ->and($parser->warnings())->toBe([]);
})->with(['3.0.0', '3.0.4', '3.1.0', '3.1.1']);

it('accepts a 3.2 document best-effort with a loud warning naming #102 and the version matrix', function () {
    $path = writeTempSpec('v32.json', '{"openapi":"3.2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    $parser = new SpecParser;
    $document = $parser->parseFileToDocument($path);

    expect($document->openapi)->toBe('3.2.0')
        ->and($parser->warnings())->toHaveCount(1)
        ->and($parser->warnings()[0])->toContain('OpenAPI 3.2 is not fully supported yet')
        ->and($parser->warnings()[0])->toContain('accepted best-effort')
        ->and($parser->warnings()[0])->toContain('https://github.com/codewithagents/openapi-laravel/issues/102')
        ->and($parser->warnings()[0])->toContain('Supported versions: OpenAPI 3.0.x and 3.1.x')
        ->and($parser->warnings()[0])->toContain('https://openapi-laravel.codewithagents.de/guides/openapi-versions/');
});

it('mirrors the document warnings into warnings() and resets them between parses', function () {
    $parser = new SpecParser;

    $document = $parser->parseFileToDocument(__DIR__.'/../../Fixtures/edge/openapi-3.2-constructs.yaml');

    // The headline best-effort warning plus exactly one per construct.
    expect($document->warnings)->toHaveCount(4)
        ->and($parser->warnings())->toBe($document->warnings)
        ->and(implode("\n", $document->warnings))
        ->toContain('OpenAPI 3.2 `query` operation at paths./things was dropped: QUERY routes are not generated yet.')
        ->toContain('OpenAPI 3.2 `additionalOperations` at paths./things were dropped: custom-method routes are not generated yet.')
        ->toContain('OpenAPI 3.2 `itemSchema` at paths./things/stream.get.responses.200.content.application/jsonl was dropped: sequential media types are not read yet.');

    $clean = writeTempSpec('clean.json', MINIMAL_JSON);
    $parser->parseFileToDocument($clean);

    expect($parser->warnings())->toBe([]);
});

it('rejects an unsupported version naming the supported matrix', function (string $version) {
    $path = writeTempSpec('unsupported.json', '{"openapi":"'.$version.'","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    expect(fn () => (new SpecParser)->parseFileToDocument($path))
        ->toThrow(ParseException::class, "Unsupported OpenAPI version '{$version}'")
        ->toThrow(ParseException::class, 'Supported versions: OpenAPI 3.0.x and 3.1.x (fully), 3.2.x (accepted best-effort with warnings)')
        ->toThrow(ParseException::class, 'https://openapi-laravel.codewithagents.de/guides/openapi-versions/');
})->with(['2.0', '3.3.0', '4.0.0', '4.1.0', '30.0.0', 'garbage']);

// B-1: a pre-parse size guard bounds the cost of YAML alias/anchor expansion.

it('rejects a spec larger than the configured size limit', function () {
    $path = writeTempSpec('big.json', str_repeat(' ', 2048).MINIMAL_JSON);

    (new SpecParser(maxBytes: 1024))->parseFileToDocument($path);
})->throws(ParseException::class, 'too large');

it('accepts a spec within the size limit', function () {
    $path = writeTempSpec('small.json', MINIMAL_JSON);

    expect((new SpecParser(maxBytes: 1_048_576))->parseFileToDocument($path)->openapi)->toBe('3.0.3');
});

// #20: boolean `items` is valid OpenAPI 3.1 (`items: true`, and the
// closed-tuple `prefixItems` + `items: false`). The reader folds the
// normalization: `items: true` becomes an empty schema, `items: false` is
// dropped with the closed-tuple length surviving as a synthesized maxItems
// (#82). This pins the fold end-to-end through the file path.

const BOOLEAN_ITEMS_YAML = <<<'YAML'
openapi: 3.1.0
info:
  title: Boolean items
  version: 1.0.0
paths: {}
components:
  schemas:
    AnyItems:
      type: array
      items: true
    ClosedTuple:
      type: array
      prefixItems:
        - type: string
        - type: integer
      items: false
YAML;

it('folds the boolean items normalization into the parsed document (#20, #82)', function () {
    $path = writeTempSpec('boolean-items.yaml', BOOLEAN_ITEMS_YAML);

    $document = (new SpecParser)->parseFileToDocument($path);

    expect($document->openapi)->toBe('3.1.0');

    $schemas = $document->components?->schemas ?? [];
    $anyItems = $schemas['AnyItems'] ?? null;
    $closedTuple = $schemas['ClosedTuple'] ?? null;
    assert($anyItems instanceof SchemaNode);
    assert($closedTuple instanceof SchemaNode);

    expect($anyItems->items)->not->toBeNull()
        ->and($closedTuple->items)->toBeNull()
        ->and($closedTuple->prefixItems)->toHaveCount(2)
        ->and($closedTuple->maxItems)->toBe(2);
});
