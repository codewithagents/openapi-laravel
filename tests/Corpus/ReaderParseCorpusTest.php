<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * #104 smoke gate: the reader must hydrate the heaviest, most keyword-dense
 * real-world specs in the corpus without throwing, and produce a non-trivial
 * graph. ParseCorpusTest covers all 130 specs; this adds shape assertions for
 * the specs most likely to expose hydration gaps.
 */
it('hydrates the most complex corpus specs through the reader', function (string $fixture) {
    $document = (new SpecParser)->parseFileToDocument(__DIR__.'/../Fixtures/specs/'.$fixture);

    expect($document)->toBeInstanceOf(OpenApiDocument::class)
        ->and($document->openapi)->toMatch('/^3\.(0|1)/')
        ->and(count($document->paths))->toBeGreaterThan(0)
        ->and(count($document->components?->schemas ?? []))->toBeGreaterThan(0);
})->with([
    'stripe.json',
    'github.json',
    'box.json',
    'adyen-checkout.yaml',
    'adyen-legal-entity.yaml',
    'asana.json',
    'sentry.json',
    'twilio.json',
    'twilio_api_v2010.json',
    'petstore-3.0.yaml',
]);
