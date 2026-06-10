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
