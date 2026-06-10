<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Snapshots the full server scaffold (abstract controllers + routes file) for
 * the real petstore-3.0 spec, so the generated API shape is diff-visible.
 */
it('matches the committed server-scaffold snapshot for petstore-3.0', function () {
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/specs/petstore-3.0.yaml');

    $generator = new ModelGenerator;
    $generator->generate($doc);
    $registry = $generator->registry();
    $options = new ServerOptions;

    $controllers = (new ControllerGenerator($options, $registry))->generate($doc);
    $descriptors = (new OperationCollector($options, $registry))->collect($doc);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $combined = implode("\n", array_map(fn ($f) => $f->code, $controllers))."\n".$routes->code;

    expect($combined)->toMatchSnapshot();
});
