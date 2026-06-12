<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The tag-grouped data layout gate (issue #93, the only layout) over a
 * deliberate corpus subset: real specs with large tag fan-out, shared
 * schemas, discriminated unions, and missing tags must still produce valid
 * PHP when the data layer is split into per-tag namespaces. The full corpus
 * gates exercise the same layout; this subset adds the stricter
 * namespace-aware import resolution below.
 *
 * Beyond the syntax gate, this runs a GROUPED-AWARE import-resolution check:
 * the flat-mode gate (tests/Pest.php) treats any short name defined anywhere
 * in the output as resolved, which is only sound when everything shares one
 * namespace. Here every signature reference must resolve IN ITS FILE'S OWN
 * NAMESPACE: imported, defined in the same namespace, or a PHP builtin. That
 * is exactly the bug class the grouped layout could introduce (a cross-group
 * reference emitted by short name without its import).
 */

/**
 * The declared namespace of a generated file.
 */
function declaredNamespace(string $code): string
{
    return preg_match('/^namespace ([^;]+);$/m', $code, $match) === 1 ? $match[1] : '';
}

/**
 * Lower-cased short name => declared namespace, for every class-like
 * definition across the generated set. Generated short names are globally
 * unique (one shared allocator), so the map is unambiguous.
 *
 * @param  iterable<GeneratedFile>  $files
 * @return array<string, string>
 */
function definedClassNamespaces(iterable $files): array
{
    $map = [];
    foreach ($files as $file) {
        $namespace = declaredNamespace($file->code);
        foreach (array_keys(definedClassNames([$file])) as $short) {
            $map[$short] = $namespace;
        }
    }

    return $map;
}

it('generates valid, namespace-resolvable PHP with the grouped layout', function (string $spec) {
    $path = __DIR__.'/../Fixtures/specs/'.$spec;
    $parser104 = new SpecParser;
    $document = $parser104->parseFileToDocument($path);
    $documentCebe = $parser104->buildCebeModel($document, $path);

    // The default construction: the generator computes the tag attribution
    // from the document itself, exactly like the planner-wired production run.
    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);

    $serverOptions = new ServerOptions;
    $descriptors = (new OperationCollector($serverOptions, $generator->registry(), null, $generator))->collect($documentCebe);
    $controllers = (new ControllerGenerator($serverOptions))->generate($descriptors);
    $routes = (new RouteGenerator($serverOptions))->generate($descriptors);

    $dataFiles = [...array_values($modelFiles), ...array_values($generator->queryFiles()), ...array_values($generator->bodyFiles())];
    $allFiles = [...$dataFiles, ...array_values($generator->supportFiles()), ...array_values($controllers), $routes];

    // Syntax gate: every emitted file must parse.
    foreach ($allFiles as $file) {
        try {
            token_get_all($file->code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $this->fail("Invalid PHP in {$file->filename()} (from {$spec}, grouped layout): {$e->getMessage()}\n\n".$file->code);
        }
    }

    // Grouped import-resolution gate: every class short name a signature
    // references must be imported or defined in the SAME namespace.
    $definedByNamespace = definedClassNamespaces($allFiles);
    foreach ($allFiles as $file) {
        $namespace = declaredNamespace($file->code);

        // Resolve against only the names local to this file's namespace; the
        // shared helper then treats everything else as needing an import.
        $localNames = [];
        foreach ($definedByNamespace as $short => $definedIn) {
            if ($definedIn === $namespace) {
                $localNames[$short] = true;
            }
        }

        $unresolved = unresolvedSignatureTypes($file->code, $localNames);
        if ($unresolved !== []) {
            $this->fail(
                'Unresolved cross-namespace reference(s) ['.implode(', ', $unresolved)."] in {$file->filename()} ".
                '(from '.$spec.", grouped layout): used in a signature without an import or a same-namespace definition.\n\n".$file->code
            );
        }
    }

    // The layout invariant: every file's namespace matches its directory, and
    // at least the flat root namespace stays the configured one.
    foreach ($dataFiles as $file) {
        $expected = $file->directory === null ? 'App\\Data' : 'App\\Data\\'.$file->directory;
        expect(declaredNamespace($file->code))->toBe($expected);
    }

    // Compile gate: php -l over a deterministic sample of the grouped output
    // (the full corpus-wide sweep stays with the flat php -l gate).
    $failures = phpLintFailures($dataFiles, $spec.' (grouped layout)', 25);
    expect($failures)->toBe([]);
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

it('actually groups real-world specs: at least one corpus spec emits a per-tag directory', function () {
    $parser104 = new SpecParser;
    $document = $parser104->parseFileToDocument(__DIR__.'/../Fixtures/specs/petstore-3.0.yaml');
    $documentCebe = $parser104->buildCebeModel($document, __DIR__.'/../Fixtures/specs/petstore-3.0.yaml');

    $generator = new ModelGenerator;
    $files = $generator->generate($document);

    $directories = [];
    foreach ($files as $file) {
        if ($file->directory !== null) {
            $directories[$file->directory] = true;
        }
    }

    // The petstore's tags (pet, store, user) own schemas exclusively, so the
    // grouped layout must actually fire on real input, not just fixtures.
    expect($directories)->not->toBe([]);
});
