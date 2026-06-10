<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

function writeTempSpec(string $name, string $contents): string
{
    $path = sys_get_temp_dir().'/openapi-laravel-test-'.uniqid().'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}

const MINIMAL_JSON = '{"openapi":"3.0.3","info":{"title":"T","version":"1.0.0"},"paths":{}}';

const MINIMAL_YAML = "openapi: 3.0.3\ninfo:\n  title: T\n  version: 1.0.0\npaths: {}\n";

it('parses a JSON document', function () {
    $path = writeTempSpec('spec.json', MINIMAL_JSON);

    $doc = (new SpecParser)->parseFile($path);

    expect($doc->info->title)->toBe('T');
});

it('parses a YAML document', function () {
    $path = writeTempSpec('spec.yaml', MINIMAL_YAML);

    $doc = (new SpecParser)->parseFile($path);

    expect($doc->info->version)->toBe('1.0.0');
});

it('detects JSON content for an unknown extension', function () {
    $path = writeTempSpec('spec.txt', MINIMAL_JSON);

    $doc = (new SpecParser)->parseFile($path);

    expect($doc->info->title)->toBe('T');
});

it('throws for a missing file', function () {
    (new SpecParser)->parseFile('/no/such/spec.json');
})->throws(ParseException::class, 'not found');

it('throws for malformed content', function () {
    $path = writeTempSpec('broken.json', '{not valid json');

    (new SpecParser)->parseFile($path);
})->throws(ParseException::class);

it('rejects an invalid document when validation is requested', function () {
    // Missing required "info" and "paths".
    $path = writeTempSpec('invalid.json', '{"openapi":"3.0.3"}');

    (new SpecParser)->parseFile($path, validate: true);
})->throws(ParseException::class);

// A-1: a non-OpenAPI-3.x document must fail loudly, not parse into a
// null-filled object that silently produces nothing.

it('rejects a Swagger 2.0 document by version', function () {
    $path = writeTempSpec('swagger.json', '{"swagger":"2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    (new SpecParser)->parseFile($path);
})->throws(ParseException::class, 'Not an OpenAPI 3.x document');

it('rejects an OpenAPI 2.x version string', function () {
    $path = writeTempSpec('v2.json', '{"openapi":"2.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    (new SpecParser)->parseFile($path);
})->throws(ParseException::class, 'Unsupported OpenAPI version');

it('rejects a document missing the info object', function () {
    $path = writeTempSpec('noinfo.json', '{"openapi":"3.0.3","paths":{}}');

    (new SpecParser)->parseFile($path);
})->throws(ParseException::class, "missing required 'info'");

it('rejects an empty file with a clear error, not a silent success', function () {
    $path = writeTempSpec('empty.yaml', '');

    (new SpecParser)->parseFile($path);
})->throws(ParseException::class);

it('accepts an OpenAPI 3.1 document', function () {
    $path = writeTempSpec('v31.json', '{"openapi":"3.1.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    expect((new SpecParser)->parseFile($path)->openapi)->toBe('3.1.0');
});

// B-1: a pre-parse size guard bounds the cost of YAML alias/anchor expansion.

it('rejects a spec larger than the configured size limit', function () {
    $path = writeTempSpec('big.json', str_repeat(' ', 2048).MINIMAL_JSON);

    (new SpecParser(maxBytes: 1024))->parseFile($path);
})->throws(ParseException::class, 'too large');

it('accepts a spec within the size limit', function () {
    $path = writeTempSpec('small.json', MINIMAL_JSON);

    expect((new SpecParser(maxBytes: 1_048_576))->parseFile($path)->openapi)->toBe('3.0.3');
});

// #20: cebe cannot instantiate a Schema from a boolean, so valid OpenAPI 3.1
// boolean `items` (`items: true`, and the closed-tuple `prefixItems` + `items:
// false`) previously threw "Unable to instantiate Schema Object with data ''".
// This is the exact construct the conformance fixture had to drop. The Parser
// now normalises boolean `items` before cebe sees it, so the document parses.

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

it('parses a 3.1 spec with boolean items: true and a closed tuple (items: false)', function () {
    $path = writeTempSpec('boolean-items.yaml', BOOLEAN_ITEMS_YAML);

    // Previously this threw a ParseException ("Unable to instantiate Schema
    // Object with data ''"). It must now parse cleanly.
    $document = (new SpecParser)->parseFile($path);

    expect($document->openapi)->toBe('3.1.0');

    $schemas = $document->components->schemas;

    // `items: true` normalised to an empty schema (any).
    expect($schemas['AnyItems']->items)->not->toBeNull();

    // `items: false` dropped; the tuple is still described by prefixItems.
    expect($schemas['ClosedTuple']->prefixItems)->toHaveCount(2);
});
