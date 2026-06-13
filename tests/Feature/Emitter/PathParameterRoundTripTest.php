<?php

declare(strict_types=1);

use App\Data\Widget\GetPlainPathData;
use App\Data\Widget\GetReportPathData;
use App\Data\Widget\GetWidgetPartPathData;
use App\Data\Widget\ReportStatus;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * End-to-end for the per-operation path Data classes (issue #113): generate
 * from a spec with constrained `in: path` parameters, load the emitted classes
 * into the booted Laravel app, and drive `fromRoute()` with REAL Request
 * objects carrying resolved route parameters, so the payloads are exactly what
 * Laravel's router binds (path segments arrive as strings). Proves the
 * generated rules() accept spec-valid and reject spec-invalid path values
 * through the actual Laravel validator, and that hydration reads the route
 * parameters only (not the query string or body).
 */
beforeEach(function () {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $dir = sys_get_temp_dir().'/oal_path_roundtrip_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $parser = new SpecParser;
    $document = $parser->parseFileToDocument(__DIR__.'/../../Fixtures/server/path-parameter-constraints.yaml');
    $generator = new ModelGenerator;
    $files = $generator->generate($document);
    // The collector emits the path classes as a side effect, exactly like the
    // planner wiring.
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($document);

    loadGeneratedFiles($dir, [
        ...array_values($files),
        ...array_values($generator->pathFiles()),
    ]);
});

/**
 * Build a Request whose resolved route parameters are exactly $parameters, the
 * shape `$request->route()->parameters()` returns in production. The route's
 * URI is irrelevant to fromRoute (it reads the bound parameters, not the path),
 * so a placeholder URI keeps the helper trivial.
 *
 * @param  array<string, string>  $parameters
 */
function pathRequest(array $parameters): Request
{
    $request = Request::create('/', 'GET');
    $route = new Route('GET', '/', []);
    $route->bind($request);
    foreach ($parameters as $name => $value) {
        $route->setParameter($name, $value);
    }
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('hydrates typed properties from resolved route parameters', function () {
    $path = GetWidgetPartPathData::fromRoute(pathRequest(['widgetId' => '7', 'partCode' => 'abc']));

    expect($path->widgetId)->toBe(7)
        ->and($path->partCode)->toBe('abc');
});

it('hydrates an enum path parameter', function () {
    $path = GetReportPathData::fromRoute(pathRequest([
        'reportId' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
        'status' => 'closed',
    ]));

    expect($path->status)->toBe(ReportStatus::Closed)
        ->and($path->reportId)->toBe('3fa85f64-5717-4562-b3fc-2c963f66afa6');
});

it('rejects an integer below the spec minimum', function () {
    GetWidgetPartPathData::fromRoute(pathRequest(['widgetId' => '0', 'partCode' => 'abc']));
})->throws(ValidationException::class);

it('rejects an integer above the spec maximum', function () {
    GetWidgetPartPathData::fromRoute(pathRequest(['widgetId' => '1001', 'partCode' => 'abc']));
})->throws(ValidationException::class);

it('rejects a non-integer value for an integer parameter', function () {
    GetWidgetPartPathData::fromRoute(pathRequest(['widgetId' => 'soon', 'partCode' => 'abc']));
})->throws(ValidationException::class);

it('rejects a value violating the spec pattern', function () {
    GetWidgetPartPathData::fromRoute(pathRequest(['widgetId' => '7', 'partCode' => 'ABCD']));
})->throws(ValidationException::class);

it('rejects an enum value outside the spec set', function () {
    GetReportPathData::fromRoute(pathRequest([
        'reportId' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
        'status' => 'deleted',
    ]));
})->throws(ValidationException::class);

it('rejects a malformed uuid for a uuid-format parameter', function () {
    GetReportPathData::fromRoute(pathRequest([
        'reportId' => 'not-a-uuid',
        'status' => 'open',
    ]));
})->throws(ValidationException::class);

it('hydrates from route parameters only: query and body values never bleed in', function () {
    // The query string and body smuggle a conflicting id; the route binds the
    // valid one, so hydration must use the route value, not the merged input.
    $request = Request::create('/?id=999', 'GET', ['id' => 'banana']);
    $route = new Route('GET', '/plain/{id}', []);
    $route->bind($request);
    $route->setParameter('id', '42');
    $request->setRouteResolver(fn () => $route);

    $path = GetPlainPathData::fromRoute($request);

    expect($path->id)->toBe(42);
});

it('rejects an invalid route value even when the query string carries a valid one', function () {
    $request = Request::create('/?id=5', 'GET');
    $route = new Route('GET', '/plain/{id}', []);
    $route->bind($request);
    $route->setParameter('id', 'soon');
    $request->setRouteResolver(fn () => $route);

    GetPlainPathData::fromRoute($request);
})->throws(ValidationException::class);
