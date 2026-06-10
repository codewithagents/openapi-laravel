<?php

declare(strict_types=1);

use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Smoke test: every real-world fixture in the corpus must parse and resolve
 * without error. This is the broadest guard against parser regressions.
 */
it('parses every corpus spec', function (string $path) {
    $document = (new SpecParser)->parseFile($path);

    expect($document)->toBeInstanceOf(OpenApi::class)
        ->and($document->openapi)->toMatch('/^3\.(0|1)/');
})->with('corpus_specs');

it('covers the full corpus', function () {
    $files = glob(__DIR__.'/../Fixtures/specs/*.{json,yaml,yml}', GLOB_BRACE) ?: [];

    expect(count($files))->toBe(128);
});
