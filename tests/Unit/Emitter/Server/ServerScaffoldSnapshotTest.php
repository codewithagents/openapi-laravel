<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Snapshots the full server scaffold (abstract controllers, per-operation
 * query and inline request-body Data classes, and the routes file) for the
 * real petstore-3.0 spec, so the generated API shape is diff-visible. The
 * collector is wired with the model generator exactly like the planner does,
 * so the query classes (issue #63) and inline-body classes (issue #76) are
 * part of the snapshot.
 */
it('matches the committed server-scaffold snapshot for petstore-3.0', function () {
    $parser104 = new SpecParser;
    $doc = $parser104->parseFileToDocument(__DIR__.'/../../../Fixtures/specs/petstore-3.0.yaml');
    $docCebe = $parser104->buildCebeModel($doc, __DIR__.'/../../../Fixtures/specs/petstore-3.0.yaml');

    $generator = new ModelGenerator;
    $generator->generate($doc);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($docCebe);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $combined = implode("\n", array_map(fn ($f) => $f->code, $controllers))
        ."\n".implode("\n", array_map(fn ($f) => $f->code, $generator->queryFiles()))
        ."\n".implode("\n", array_map(fn ($f) => $f->code, $generator->bodyFiles()))
        ."\n".$routes->code;

    expect($combined)->toMatchSnapshot();
});
