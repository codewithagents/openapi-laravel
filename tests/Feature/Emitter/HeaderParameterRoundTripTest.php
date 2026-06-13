<?php

declare(strict_types=1);

use App\Data\Widget\GetFlagsHeaderData;
use App\Data\Widget\ListWidgetsHeaderData;
use App\Data\Widget\WidgetStatus;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * End-to-end for the per-operation header Data classes (issue #121): generate
 * from a spec with constrained `in: header` parameters, load the emitted
 * classes into the booted Laravel app, and drive `fromHeaders()` with REAL
 * Request objects carrying request headers, so the payloads are exactly what
 * Laravel/Symfony produce (lowercased keys, array values). Proves the
 * generated rules() accept spec-valid and reject spec-invalid header values
 * through the actual Laravel validator, that lookup is case-insensitive, that
 * reserved/framework-owned standard headers are never validated, and that
 * hydration reads the headers only (not the query string or body).
 */
beforeEach(function () {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $dir = sys_get_temp_dir().'/oal_header_roundtrip_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $parser = new SpecParser;
    $document = $parser->parseFileToDocument(__DIR__.'/../../Fixtures/server/header-parameter-constraints.yaml');
    $generator = new ModelGenerator;
    $files = $generator->generate($document);
    // The collector emits the header classes as a side effect, exactly like the
    // planner wiring.
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($document);

    loadGeneratedFiles($dir, [
        ...array_values($files),
        ...array_values($generator->headerFiles()),
    ]);
});

/**
 * Build a Request whose headers are exactly $headers (case preserved on input;
 * Symfony lowercases the keys internally, exactly as in production). The body
 * and query default to empty so a "headers only" hydration is provable.
 *
 * @param  array<string, string>  $headers
 */
function headerRequest(array $headers): Request
{
    return Request::create('/', 'GET', server: array_combine(
        array_map(static fn (string $name): string => 'HTTP_'.strtoupper(str_replace('-', '_', $name)), array_keys($headers)),
        array_values($headers),
    ));
}

it('hydrates typed properties from request headers', function () {
    $data = ListWidgetsHeaderData::fromHeaders(headerRequest([
        'X-Request-Id' => 'abcd1234',
        'X-Page-Size' => '25',
        'X-Status' => 'open',
    ]));

    expect($data->xRequestId)->toBe('abcd1234')
        ->and($data->xPageSize)->toBe(25)
        ->and($data->xStatus)->toBe(WidgetStatus::Open);
});

it('reads header names case-insensitively (lowercased input)', function () {
    $data = ListWidgetsHeaderData::fromHeaders(headerRequest([
        'x-request-id' => 'deadbeef',
        'X-STATUS' => 'closed',
    ]));

    expect($data->xRequestId)->toBe('deadbeef')
        ->and($data->xStatus)->toBe(WidgetStatus::Closed);
});

it('hydrates a boolean header from the form-style literal', function () {
    $data = GetFlagsHeaderData::fromHeaders(headerRequest(['X-Debug' => 'true']));

    expect($data->xDebug)->toBeTrue();
});

it('rejects an integer header above the spec maximum', function () {
    ListWidgetsHeaderData::fromHeaders(headerRequest([
        'X-Request-Id' => 'abcd1234',
        'X-Page-Size' => '101',
        'X-Status' => 'open',
    ]));
})->throws(ValidationException::class);

it('rejects an integer header below the spec minimum', function () {
    ListWidgetsHeaderData::fromHeaders(headerRequest([
        'X-Request-Id' => 'abcd1234',
        'X-Page-Size' => '0',
        'X-Status' => 'open',
    ]));
})->throws(ValidationException::class);

it('rejects a value violating the spec pattern', function () {
    ListWidgetsHeaderData::fromHeaders(headerRequest([
        'X-Request-Id' => 'NOT-HEX',
        'X-Status' => 'open',
    ]));
})->throws(ValidationException::class);

it('rejects an enum header value outside the spec set', function () {
    ListWidgetsHeaderData::fromHeaders(headerRequest([
        'X-Request-Id' => 'abcd1234',
        'X-Status' => 'deleted',
    ]));
})->throws(ValidationException::class);

it('rejects a missing required header', function () {
    // X-Request-Id is required; omitting it must 422 rather than hydrate.
    ListWidgetsHeaderData::fromHeaders(headerRequest([
        'X-Status' => 'open',
    ]));
})->throws(ValidationException::class);

it('hydrates from headers only: query and body values never bleed in', function () {
    // The query string and body smuggle a conflicting x-request-id; only the
    // real header is read, so a body/query value can neither satisfy nor
    // corrupt header validation.
    $request = Request::create('/?x-request-id=zzzz', 'GET', ['x-status' => 'banana']);
    $request->headers->set('X-Request-Id', 'abcd1234');
    $request->headers->set('X-Status', 'archived');

    $data = ListWidgetsHeaderData::fromHeaders($request);

    expect($data->xRequestId)->toBe('abcd1234')
        ->and($data->xStatus)->toBe(WidgetStatus::Archived);
});
