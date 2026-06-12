<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The Laravel-convention naming gate (issue #94, the only naming) over a
 * deliberate corpus subset: real-world specs full of overlapping collection
 * GETs, parameterized item paths, and missing operationIds must still
 * collect, name, and emit valid PHP under the conventional naming. The full
 * corpus gates exercise the same naming; this subset pins the route-name
 * uniqueness invariant on structurally diverse specs (REST-heavy, RPC-ish,
 * large tag fan-out).
 */
it('generates valid controllers and routes under the Laravel-convention naming', function (string $spec) {
    $path = __DIR__.'/../Fixtures/specs/'.$spec;
    $parser104 = new SpecParser;
    $document = $parser104->parseFileToDocument($path);

    $generator = new ModelGenerator;
    $generator->generate($document);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($document);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $files = array_values($controllers);
    $files[] = $routes;

    foreach ($files as $file) {
        try {
            token_get_all($file->code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $this->fail("Invalid PHP in {$file->filename()} (from {$spec}, conventional naming): {$e->getMessage()}\n\n".$file->code);
        }
    }

    // Method names and route names must stay unique where the language and
    // Laravel require it: per controller for methods (one abstract class per
    // tag in $controllers, so duplicate methods would already fail the parse
    // gate above), globally for route names.
    $routeNames = [];
    foreach ($descriptors as $descriptor) {
        $routeNames[] = $descriptor->routeName;
    }
    expect($routeNames)->toBe(array_values(array_unique($routeNames)));
})->with([
    'petstore-3.0.yaml',
    'spotify.yaml',
    'slack.json',
    'twilio_messaging.json',
    'sendgrid.json',
    'box.json',
    'asana.json',
    'sentry.json',
]);
