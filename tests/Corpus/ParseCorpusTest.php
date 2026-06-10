<?php

declare(strict_types=1);

use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Smoke test: every real-world fixture in the corpus must parse and resolve
 * without error. This is the broadest guard against parser regressions.
 *
 * @return array<string, array{string}>
 */
function corpusSpecs(): array
{
    $files = glob(__DIR__.'/../Fixtures/specs/*.{json,yaml,yml}', GLOB_BRACE) ?: [];

    $cases = [];
    foreach ($files as $file) {
        $cases[basename($file)] = [$file];
    }

    return $cases;
}

it('parses every corpus spec', function (string $path) {
    $document = (new SpecParser)->parseFile($path);

    expect($document)->toBeInstanceOf(OpenApi::class)
        ->and($document->openapi)->toMatch('/^3\.(0|1)/');
})->with(corpusSpecs());

it('covers the full corpus', function () {
    expect(count(corpusSpecs()))->toBe(128);
});
