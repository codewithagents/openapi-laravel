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
 * operations and emitting controllers, per-operation query Data classes
 * (issue #63), inline request-body Data classes (issue #76), and routes must
 * never throw, and every generated file must be syntactically valid PHP.
 * Robustness is the contract: missing operationIds, absent tags, non-object
 * inline bodies, weird path tokens, un-serializable query parameters, and
 * unresolved $refs must degrade (Request / JsonResponse / skip-with-warning),
 * never fatal.
 */
it('generates valid controllers and routes for every corpus spec', function (string $path) {
    $parser104 = new SpecParser;
    $document = $parser104->parseFileToDocument($path);
    $documentCebe = $parser104->buildCebeModel($document, $path);

    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);
    $registry = $generator->registry();
    $options = new ServerOptions;

    // Collect once, share with both generators (mirrors the command/standalone
    // wiring), with the model generator wired in so the per-operation query
    // Data classes are emitted and gated too.
    $descriptors = (new OperationCollector($options, $registry, null, $generator))->collect($documentCebe);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);
    $queryFiles = array_values($generator->queryFiles());
    $bodyFiles = array_values($generator->bodyFiles());

    $files = array_merge(array_values($controllers), $queryFiles, $bodyFiles);
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
