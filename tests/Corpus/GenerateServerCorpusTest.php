<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The server-scaffold gate: for every real-world corpus spec, collecting
 * operations and emitting controllers + routes must never throw, and every
 * generated file must be syntactically valid PHP. Robustness is the contract:
 * missing operationIds, absent tags, inline bodies, weird path tokens, and
 * unresolved $refs must degrade to Request / JsonResponse, never fatal.
 */
it('generates valid controllers and routes for every corpus spec', function (string $path) {
    $document = (new SpecParser)->parseFile($path);

    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);
    $registry = $generator->registry();
    $options = new ServerOptions;

    // Collect once, share with both generators (mirrors the command/standalone wiring).
    $descriptors = (new OperationCollector($options, $registry))->collect($document);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $files = $controllers;
    $files[] = $routes;

    foreach ($files as $file) {
        try {
            token_get_all($file->code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $this->fail(
                "Invalid PHP in {$file->filename()} (from ".basename($path)."): {$e->getMessage()}\n\n".$file->code
            );
        }
    }

    // Import-resolution gate: a controller may parse cleanly yet reference a
    // class it never imported (the historic unimported-`Request` bug). Assert
    // every class short-name used in a generated signature resolves to an
    // import, a generated class, or an allowlisted builtin. The defined-name
    // set spans the generated Data classes too, since controllers reference
    // them across files.
    $defined = definedClassNames(array_merge(array_values($modelFiles), $files));
    foreach ($files as $file) {
        $unresolved = unresolvedSignatureTypes($file->code, $defined);
        if ($unresolved !== []) {
            $this->fail(
                'Unresolved class reference(s) ['.implode(', ', $unresolved)."] in {$file->filename()} ".
                '(from '.basename($path)."): used in a signature without an import or definition.\n\n".$file->code
            );
        }
    }

    expect(true)->toBeTrue();
})->with('corpus_specs');
