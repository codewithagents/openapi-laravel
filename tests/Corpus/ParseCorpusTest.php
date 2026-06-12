<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Smoke test: every real-world fixture in the corpus must parse into the
 * typed document graph without error. This is the broadest guard against
 * parser regressions.
 */
it('parses every corpus spec', function (string $path) {
    $document = (new SpecParser)->parseFileToDocument($path);

    // 3.0.x and 3.1.x are fully supported; the openapi-3.2-* fixtures (#104
    // T8) are accepted best-effort with the loud #103 warning, asserted in
    // detail by OpenApi32CorpusTest.
    expect($document)->toBeInstanceOf(OpenApiDocument::class)
        ->and($document->openapi)->toMatch('/^3\.(0|1|2)/');
})->with('corpus_specs');

it('covers the full corpus', function () {
    $files = glob(__DIR__.'/../Fixtures/specs/*.{json,yaml,yml}', GLOB_BRACE) ?: [];

    // 130 original specs plus the 5 OpenAPI 3.2 fixtures added in #104 T8.
    expect(count($files))->toBe(135);
});
