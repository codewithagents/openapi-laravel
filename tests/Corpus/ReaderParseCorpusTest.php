<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * #104 T2 smoke gate: the new reader path must hydrate the heaviest, most
 * keyword-dense real-world specs in the corpus without throwing. The full
 * 130-spec byte-identical comparison against the cebe path is Task 3; this
 * pins the reader against the specs most likely to expose hydration gaps.
 */
it('hydrates the most complex corpus specs through the new reader path', function (string $fixture) {
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
