<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Static-analysis gate (issue #62).
 *
 * The sibling Pint gate (#60, GeneratedOutputPintTest) proves the generated
 * output is born style-clean. This gate proves it is also born clean under
 * PHPStan at max, the same level we hold our own source to. A Laravel consumer
 * who commits the generated Data classes and runs `phpstan analyse` (standard in
 * Laravel CI) must not get red from our output through no fault of their own.
 *
 * It runs the real `phpstan analyse --level=max` over generated output in a temp
 * dir. Since issue #40, the generated code imports its rules and transformer
 * from the consumer's own `App\Data\Support\...` namespace, so the inlined
 * support classes are written alongside the Data classes and analysed too (the
 * generator package is no longer a runtime dependency of the output). Larastan is
 * included so the spatie/laravel-data attributes resolve. Any error fails the test.
 *
 * Two emission bugs this locks down (both real, not just docblock gaps):
 *   - missingType.iterableValue: a nested array/map element must carry its own
 *     generic (`array<int, array<int, int>>`), never a bare inner `array`.
 *   - argument.type: an array of a backed enum must NOT get a
 *     `#[DataCollectionOf(SomeEnum::class)]` attribute (DataCollectionOf targets
 *     class-string<BaseData>; an enum fails it and throws at runtime).
 *   - return.type: the `rules()` docblock keys are `array-key`, not `string`, so
 *     a numeric-string property name (e.g. a `+1`/`-1` reaction count) that PHP
 *     coerces to an int array key still matches the declared return type.
 *
 * Running PHPStan over all 130 corpus specs would be too slow for a unit test, so
 * the gate is scoped to the conformance fixtures (the comprehensive synthetic
 * construct surface) plus a representative real-world subset that between them
 * exercise every category above: petstore (the canonical demo), github (nested
 * arrays, enum collections, numeric-string keyed reaction maps), stripe (large
 * map surface), box and adyen-checkout (further real shapes). The covered set is
 * logged below so the scope is explicit, never a silent cap.
 */
it('generates PHPStan-max-clean output (phpstan analyse reports no errors)', function () {
    $repoRoot = dirname(__DIR__, 2);

    /**
     * Conformance fixtures plus a representative corpus subset. Running all 130
     * corpus specs through PHPStan is too slow for a unit test; this subset spans
     * every error category the gate guards (see the docblock above).
     */
    $specs = [
        'conformance-3.1' => __DIR__.'/../Fixtures/conformance/conformance-3.1.yaml',
        'conformance-3.0-forms' => __DIR__.'/../Fixtures/conformance/conformance-3.0-forms.yaml',
        'petstore' => $repoRoot.'/examples/petstore/openapi.yaml',
        'github' => __DIR__.'/../Fixtures/specs/github.json',
        'stripe' => __DIR__.'/../Fixtures/specs/stripe.json',
        'box' => __DIR__.'/../Fixtures/specs/box.json',
        'adyen-checkout' => __DIR__.'/../Fixtures/specs/adyen-checkout.yaml',
    ];

    // Make the gate scope explicit in the test output, never a silent cap: the
    // covered specs are logged so a reader sees exactly what is and is not
    // analysed. Mirrors the STDERR reporting used by the differential and
    // golden-fidelity conformance tests.
    fwrite(STDERR, "\nPHPStan gate covers ".count($specs).' specs: '.implode(', ', array_keys($specs))
        ." (conformance surface plus a representative corpus subset; the full 130-spec corpus is intentionally not run here for speed).\n");

    $base = sys_get_temp_dir().'/openapi-laravel-phpstan-'.bin2hex(random_bytes(6));
    $analyseDir = $base.'/code';
    expect(mkdir($analyseDir, 0700, true) || is_dir($analyseDir))->toBeTrue("could not create temp dir {$analyseDir}");

    $totalFiles = 0;
    foreach ($specs as $name => $path) {
        expect(is_file($path))->toBeTrue("missing corpus spec for PHPStan gate: {$path}");

        $document = (new SpecParser)->parseFile($path);
        $generator = new ModelGenerator;
        $files = $generator->generate($document);
        // The per-operation query Data classes (issue #63) are generated output
        // too: run the collector with the generator wired in, exactly like the
        // planner, so they are analysed alongside the model classes. Stripe in
        // particular emits hundreds of them.
        (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($document);
        $files = array_merge($files, $generator->queryFiles());
        // The inlined runtime support classes (issue #40) are owned output and
        // the Data classes import them, so analyse them too: both to prove the
        // support code is itself PHPStan-max-clean in the consumer namespace, and
        // so the Data classes' `use App\Data\Support\...` imports resolve.
        // Collected AFTER the query classes so their rule references count.
        $supportFiles = $generator->supportFiles();
        expect(count($files))->toBeGreaterThan(0, "spec generated no files: {$path}");

        // One subdir per spec so identically-named classes across specs (e.g. a
        // shared ErrorData) do not collide on disk and confuse the analyser.
        $subDir = $analyseDir.'/'.$name;
        expect(mkdir($subDir, 0700, true) || is_dir($subDir))->toBeTrue("could not create temp dir {$subDir}");

        foreach ($files as $file) {
            file_put_contents($subDir.'/'.$file->filename(), $file->code);
        }
        // The support classes live in `<Data>\Support`, so a `Support/` subdir
        // keeps their files namespaced realistically next to the Data classes.
        if ($supportFiles !== []) {
            $supportSubDir = $subDir.'/Support';
            expect(mkdir($supportSubDir, 0700, true) || is_dir($supportSubDir))->toBeTrue("could not create temp dir {$supportSubDir}");
            foreach ($supportFiles as $file) {
                file_put_contents($supportSubDir.'/'.$file->filename(), $file->code);
            }
        }
        $totalFiles += count($files) + count($supportFiles);
    }

    // The generated code declares `namespace App\Data;` and imports its support
    // classes from the consumer's own `App\Data\Support` namespace (issue #40),
    // both of which are written into the analysed tree above, so the package
    // autoloader plus larastan (for the spatie/laravel-data attribute classes) is
    // all PHPStan needs. A unique tmpDir keeps the result cache from leaking
    // across runs.
    $neon = $base.'/phpstan.neon';
    $config = implode("\n", [
        'includes:',
        '    - '.$repoRoot.'/vendor/larastan/larastan/extension.neon',
        '',
        'parameters:',
        '    level: max',
        '    paths:',
        '        - '.$analyseDir,
        '    bootstrapFiles:',
        '        - '.$repoRoot.'/vendor/autoload.php',
        '    tmpDir: '.$base.'/phpstan-cache',
        '',
    ]);
    file_put_contents($neon, $config);

    $phpstan = $repoRoot.'/vendor/bin/phpstan';

    try {
        $command = escapeshellarg(PHP_BINARY).' -d memory_limit=2G '
            .escapeshellarg($phpstan).' analyse'
            .' --configuration='.escapeshellarg($neon)
            .' --no-progress --error-format=raw 2>&1';
        exec($command, $output, $exitCode);
    } finally {
        // Remove the whole tree (generated files, neon, cache) regardless of result.
        exec('rm -rf '.escapeshellarg($base));
    }

    expect($totalFiles)->toBeGreaterThan(0, 'no files were generated for the PHPStan gate');

    expect($exitCode)->toBe(
        0,
        "PHPStan max reported errors on generated output (analysed {$totalFiles} files):\n".implode("\n", $output),
    );
})->group('slow');
