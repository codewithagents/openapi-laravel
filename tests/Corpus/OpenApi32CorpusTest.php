<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\DiscriminatorNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OperationNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponseNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The OpenAPI 3.2 corpus gate (#104 T8). Five openapi-3.2-*.yaml fixtures
 * prove the toolchain handles newer specs end-to-end under the #103
 * best-effort contract:
 *
 *   - openapi-3.2-query-flights.yaml: the official Learn OpenAPI 3.2 QUERY
 *     example, verbatim (OAI/learn.openapis.org, examples/v3.2).
 *   - openapi-3.2-museum.yaml: the published Redocly Museum 3.1 API
 *     (redocly-museum.yaml in this corpus) faithfully upgraded to 3.2 with
 *     all four 3.2-only constructs added.
 *   - openapi-3.2-cdn-additional-operations.yaml /
 *     openapi-3.2-payments-default-mapping.yaml /
 *     openapi-3.2-logstream-item-schema.yaml: realistic per-construct
 *     fixtures authored against the 3.2.0 specification text.
 *
 * "Supported" here is exactly the #103 scope: the document parses into the
 * typed graph (the 3.2 fixed fields hydrate into their stub properties), the
 * loud best-effort warning fires plus one warning per dropped construct
 * occurrence, and generation completes producing valid compilable PHP. Full
 * 3.2 semantics (QUERY routes, additionalOperations routes, defaultMapping
 * morph routing, itemSchema sequential media types) are issue #102 and are
 * deliberately NOT asserted.
 *
 * The glob-driven corpus gates (ParseCorpusTest, GenerateCorpusTest,
 * GenerateServerCorpusTest, PhpLintCorpusTest) pick these fixtures up
 * automatically; the frozen v0.11.0 baseline gate exempts them by name
 * (READER_BASELINE_POST_FREEZE_SPECS in ReaderCorpusBaselineTest).
 */
function openApi32Fixture(string $name): string
{
    return __DIR__.'/../Fixtures/specs/'.$name;
}

const OPENAPI_32_BEST_EFFORT_NEEDLE = 'OpenAPI 3.2 is not fully supported yet';

it('accepts each 3.2 fixture best-effort with the loud #103 warning', function (string $fixture) {
    $parser = new SpecParser;
    $document = $parser->parseFileToDocument(openApi32Fixture($fixture));

    expect($document)->toBeInstanceOf(OpenApiDocument::class)
        ->and($document->openapi)->toMatch('/^3\.2/')
        ->and($document->warnings[0] ?? '')->toContain(OPENAPI_32_BEST_EFFORT_NEEDLE)
        // The document warnings are mirrored into the parser's reporting
        // surface, which is what the commands print.
        ->and($parser->warnings())->toBe($document->warnings);
})->with([
    'openapi-3.2-query-flights.yaml',
    'openapi-3.2-cdn-additional-operations.yaml',
    'openapi-3.2-payments-default-mapping.yaml',
    'openapi-3.2-logstream-item-schema.yaml',
    'openapi-3.2-museum.yaml',
]);

it('warns once per dropped 3.2-only construct occurrence', function (string $fixture, array $needles, int $expectedCount) {
    $document = (new SpecParser)->parseFileToDocument(openApi32Fixture($fixture));

    // One best-effort warning plus exactly one warning per construct
    // occurrence: nothing is dropped silently, nothing is double-reported.
    expect($document->warnings)->toHaveCount(1 + $expectedCount);
    foreach ($needles as $needle) {
        expect(implode("\n", $document->warnings))->toContain($needle);
    }
})->with([
    'query-flights: one query operation' => [
        'openapi-3.2-query-flights.yaml',
        ['OpenAPI 3.2 `query` operation at paths./flights/search was dropped'],
        1,
    ],
    'cdn: one additionalOperations map' => [
        'openapi-3.2-cdn-additional-operations.yaml',
        ['OpenAPI 3.2 `additionalOperations` at paths./objects/{objectId} were dropped'],
        1,
    ],
    'logstream: two itemSchema media types' => [
        'openapi-3.2-logstream-item-schema.yaml',
        [
            'OpenAPI 3.2 `itemSchema` at paths./builds/{buildId}/logs.get.responses.200.content.application/jsonl was dropped',
            'OpenAPI 3.2 `itemSchema` at paths./builds/{buildId}/events.get.responses.200.content.text/event-stream was dropped',
        ],
        2,
    ],
    // defaultMapping hydrates into its typed stub and the union itself
    // still generates through the regular mapping, so there is no
    // per-occurrence dropped-construct warning for it by design (#103).
    'payments: defaultMapping alone adds no dropped-construct warning' => [
        'openapi-3.2-payments-default-mapping.yaml',
        [],
        0,
    ],
    'museum: query, additionalOperations, and itemSchema combined' => [
        'openapi-3.2-museum.yaml',
        [
            'OpenAPI 3.2 `query` operation at paths./special-events was dropped',
            'OpenAPI 3.2 `additionalOperations` at paths./tickets/{ticketId}/qr were dropped',
            'OpenAPI 3.2 `itemSchema` at paths./special-events/feed.get.responses.200.content.application/jsonl was dropped',
        ],
        3,
    ],
]);

it('hydrates the 3.2 query operation into its typed stub field', function () {
    $document = (new SpecParser)->parseFileToDocument(openApi32Fixture('openapi-3.2-query-flights.yaml'));

    $query = $document->paths['/flights/search']->query;

    expect($query)->toBeInstanceOf(OperationNode::class)
        ->and($query->operationId)->toBe('searchFlights')
        ->and($query->requestBody?->content)->toHaveKey('application/json');
});

it('hydrates 3.2 additionalOperations into typed OperationNodes keyed by method', function () {
    $document = (new SpecParser)->parseFileToDocument(openApi32Fixture('openapi-3.2-cdn-additional-operations.yaml'));

    $additional = $document->paths['/objects/{objectId}']->additionalOperations;

    expect($additional)->toBeArray()
        ->and(array_keys($additional))->toBe(['PURGE', 'LOCK'])
        ->and($additional['PURGE'])->toBeInstanceOf(OperationNode::class)
        ->and($additional['PURGE']->operationId)->toBe('purgeCachedObject')
        ->and($additional['LOCK']->operationId)->toBe('lockCachedObject');
});

it('hydrates the 3.2 discriminator defaultMapping into its typed stub field', function () {
    $document = (new SpecParser)->parseFileToDocument(openApi32Fixture('openapi-3.2-payments-default-mapping.yaml'));

    $union = $document->components?->schemas['PaymentMethod'] ?? null;

    expect($union)->toBeInstanceOf(SchemaNode::class)
        ->and($union->discriminator)->toBeInstanceOf(DiscriminatorNode::class)
        ->and($union->discriminator->propertyName)->toBe('method')
        ->and($union->discriminator->mapping)->toHaveKey('bank_transfer')
        ->and($union->discriminator->defaultMapping)->toBe('#/components/schemas/BankTransferPayment');
});

it('hydrates the 3.2 media type itemSchema into its typed stub field', function () {
    $document = (new SpecParser)->parseFileToDocument(openApi32Fixture('openapi-3.2-logstream-item-schema.yaml'));

    $logs = $document->paths['/builds/{buildId}/logs']->get?->responses?->get('200');
    $events = $document->paths['/builds/{buildId}/events']->get?->responses?->get('200');

    expect($logs)->toBeInstanceOf(ResponseNode::class)
        ->and($logs->content['application/jsonl']->itemSchema)->toBeInstanceOf(ReferenceNode::class)
        ->and($logs->content['application/jsonl']->itemSchema->ref)->toBe('#/components/schemas/LogEvent')
        ->and($events)->toBeInstanceOf(ResponseNode::class)
        ->and($events->content['text/event-stream']->itemSchema)->toBeInstanceOf(SchemaNode::class)
        ->and($events->content['text/event-stream']->itemSchema->properties)->toHaveKey('data');
});

it('hydrates all four 3.2 constructs in the combined museum upgrade', function () {
    $document = (new SpecParser)->parseFileToDocument(openApi32Fixture('openapi-3.2-museum.yaml'));

    $query = $document->paths['/special-events']->query;
    $additional = $document->paths['/tickets/{ticketId}/qr']->additionalOperations;
    $union = $document->components?->schemas['TicketPayment'] ?? null;
    $feed = $document->paths['/special-events/feed']->get?->responses?->get('200');

    expect($query)->toBeInstanceOf(OperationNode::class)
        ->and($query->operationId)->toBe('searchSpecialEvents')
        ->and($additional)->toBeArray()
        ->and($additional['REPORT'])->toBeInstanceOf(OperationNode::class)
        ->and($additional['REPORT']->operationId)->toBe('reportTicketCodeScans')
        ->and($union)->toBeInstanceOf(SchemaNode::class)
        ->and($union->discriminator?->defaultMapping)->toBe('#/components/schemas/VoucherPayment')
        ->and($feed)->toBeInstanceOf(ResponseNode::class)
        ->and($feed->content['application/jsonl']->itemSchema)->toBeInstanceOf(ReferenceNode::class);
});

it('generates valid compilable PHP for each 3.2 fixture (best-effort, #103)', function (string $fixture) {
    $parser = new SpecParser;
    $document = $parser->parseFileToDocument(openApi32Fixture($fixture));

    // The full default pipeline, mirroring the command/standalone wiring:
    // models, support classes, per-operation query/body classes, controllers,
    // and routes. Completing without throwing is the first half of the
    // best-effort contract.
    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);
    $options = new ServerOptions;
    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($document);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $files = [
        ...array_values($modelFiles),
        ...array_values($generator->supportFiles()),
        ...array_values($generator->queryFiles()),
        ...array_values($generator->bodyFiles()),
        ...array_values($controllers),
        $routes,
    ];

    // Second half: the output is genuinely valid PHP. Syntax (full in-process
    // parse), import resolution (every signature type resolves), and the real
    // compiler via php -l, uncapped: the 3.2 fixtures are small enough to
    // lint in full.
    $defined = definedClassNames($files);
    foreach ($files as $file) {
        try {
            token_get_all($file->code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $this->fail("Invalid PHP in {$file->filename()} (from {$fixture}): {$e->getMessage()}\n\n".$file->code);
        }

        $unresolved = unresolvedSignatureTypes($file->code, $defined);
        if ($unresolved !== []) {
            $this->fail(
                'Unresolved class reference(s) ['.implode(', ', $unresolved)."] in {$file->filename()} (from {$fixture})."
            );
        }
    }

    $failures = phpLintFailures($files, $fixture, null);
    expect($failures)->toBe([], implode("\n", $failures));

    // And the warning stays intact through the whole run: accepted
    // best-effort, never silently.
    expect(implode("\n", $parser->warnings()))->toContain(OPENAPI_32_BEST_EFFORT_NEEDLE);
})->with([
    'openapi-3.2-query-flights.yaml',
    'openapi-3.2-cdn-additional-operations.yaml',
    'openapi-3.2-payments-default-mapping.yaml',
    'openapi-3.2-logstream-item-schema.yaml',
    'openapi-3.2-museum.yaml',
]);
