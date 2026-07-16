<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Formatter-idempotency gate (issue #60).
 *
 * The project's headline guarantee is drift-proof generation: a consumer commits
 * the generated output and a drift check (`openapi:check`) verifies it
 * byte-for-byte. Running Laravel Pint over the generated code is standard in
 * Laravel CI, so if our output is not already Pint-clean the consumer's own Pint
 * run rewrites it and the drift check goes red through no fault of theirs.
 *
 * This test generates real output into a temp dir and runs `pint --test` over it
 * with the project's own pint.json: any file Pint would reformat fails the test.
 * It locks in the born-clean emission (`?T` for a single nullable type, `{}` for
 * an empty class body) so the idempotency can never silently regress.
 *
 * It runs the conformance fixture (the comprehensive construct surface) plus a
 * couple of real corpus specs so both the synthetic kitchen-sink and genuine
 * real-world shapes are covered.
 */
it('generates Pint-idempotent output (pint --test reports no reformats)', function (string $path) {
    $parser104 = new SpecParser;
    $document = $parser104->parseFileToDocument($path);
    $generator = new ModelGenerator;
    $files = $generator->generate($document);
    // The per-operation query (issue #63), inline request-body (issue #76),
    // and shared component-response (issue #116) Data classes are part of the
    // generated output too: run the collector with the generator wired in,
    // exactly like the planner, so they are gated for Pint-idempotency.
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($document);
    $queryFiles = $generator->queryFiles();
    $bodyFiles = $generator->bodyFiles();
    $responseFiles = $generator->responseFiles();
    // The per-operation error-factory classes (`<Operation>Errors`) are owned
    // output too, so they must be born Pint-clean like every other class.
    $errorFactoryFiles = $generator->errorFactoryFiles();
    // The inlined runtime support classes (issue #40) are owned, drift-checked
    // output too, so they must be born Pint-clean exactly like the Data classes.
    // Collected AFTER the query and body classes so their rule references count.
    $supportFiles = $generator->supportFiles();

    expect(count($files))->toBeGreaterThan(0, "spec generated no files: {$path}");
    $files = array_merge(array_values($files), array_values($queryFiles), array_values($bodyFiles), array_values($responseFiles), array_values($errorFactoryFiles));

    $dir = sys_get_temp_dir().'/openapi-laravel-pint-'.bin2hex(random_bytes(6));
    expect(mkdir($dir, 0700, true) || is_dir($dir))->toBeTrue("could not create temp dir {$dir}");

    // filename() may carry a tag-group subdirectory (issue #93), so create
    // parent directories before writing, same as loadGeneratedFiles().
    foreach ([...$files, ...array_values($supportFiles)] as $file) {
        $path = $dir.'/'.$file->filename();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $file->code);
    }

    $repoRoot = dirname(__DIR__, 2);
    $pint = $repoRoot.'/vendor/bin/pint';
    $config = $repoRoot.'/pint.json';

    try {
        // `pint --test` exits non-zero and prints the would-be-changed files when
        // the input is not already preset-clean; a zero exit means born-clean.
        $command = escapeshellarg($pint).' --test --config='.escapeshellarg($config).' '.escapeshellarg($dir).' 2>&1';
        exec($command, $output, $exitCode);
    } finally {
        // Remove the whole tree (including any tag-group subdirectories)
        // regardless of result, mirroring the PHPStan gate's cleanup.
        exec('rm -rf '.escapeshellarg($dir));
    }

    expect($exitCode)->toBe(
        0,
        "Pint reformatted generated output from {$path} (not idempotent):\n".implode("\n", $output),
    );
})->with([
    'conformance-3.1' => [__DIR__.'/../Fixtures/conformance/conformance-3.1.yaml'],
    'conformance-3.0-forms' => [__DIR__.'/../Fixtures/conformance/conformance-3.0-forms.yaml'],
    'corpus: petstore' => [__DIR__.'/../../examples/petstore/openapi.yaml'],
    'corpus: github' => [__DIR__.'/../Fixtures/specs/github.json'],
    // #104 T8: the combined OpenAPI 3.2 fixture, so best-effort 3.2 output
    // is held to the same born-Pint-clean bar as fully supported versions.
    'corpus: openapi-3.2-museum' => [__DIR__.'/../Fixtures/specs/openapi-3.2-museum.yaml'],
])->group('slow');
