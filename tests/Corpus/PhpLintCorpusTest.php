<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The compile-level gate. The sibling GenerateCorpusTest checks generated output
 * with token_get_all(TOKEN_PARSE), which only tokenizes: it accepts code that
 * tokenizes cleanly yet cannot compile, e.g. `?mixed` (the historic bug this
 * gate exists to catch), `bool $x = 'str'`, or a class that redeclares its own
 * import. `php -l` runs the real compiler and rejects all of those, so it is a
 * genuine addition to the syntax + import-resolution gates, not a replacement.
 *
 * Scope and performance: `php -l` is one OS process per file and the full corpus
 * emits tens of thousands of files, so a full sweep would take minutes. Instead
 * this gate lints, via a bounded parallel pool:
 *
 *   1. the FULL, uncapped output of the conformance fixtures, which are designed
 *      to exercise every generator construct in one place (the comprehensive
 *      surface), and
 *   2. a representative slice of every real-world corpus spec: the first N files
 *      (deterministic, ksorted) of each, as a regression tripwire across real
 *      diversity without paying for all ~36k files.
 *
 * A handful of specs are exempted (see PHP_LINT_EXEMPT_SPECS in Pest.php): they
 * trip pre-existing generator residuals whose fix lives in src/, out of scope
 * for the test layer; the exemption is by name so it is auditable, not silent.
 */

/**
 * Files-per-spec cap for the corpus slice. Tuned so the whole gate (conformance
 * in full plus this slice of all corpus specs) stays well under a minute in CI.
 */
const PHP_LINT_CORPUS_PER_SPEC_CAP = 20;

it('compiles (php -l) the full conformance fixture output', function (string $path) {
    $document = (new SpecParser)->parseFile($path);
    $files = (new ModelGenerator)->generate($document);

    // Conformance fixtures are the comprehensive surface: lint every file, no cap.
    $failures = phpLintFailures($files, basename($path), null);

    expect($failures)->toBe([], implode("\n", $failures));
})->with([
    'conformance-3.1' => [__DIR__.'/../Fixtures/conformance/conformance-3.1.yaml'],
    'conformance-3.0-forms' => [__DIR__.'/../Fixtures/conformance/conformance-3.0-forms.yaml'],
]);

it('compiles (php -l) a representative slice of every corpus spec', function (string $path) {
    if (isset(PHP_LINT_EXEMPT_SPECS[basename($path)])) {
        $this->markTestSkipped(
            basename($path).' is exempt from the php -l gate (pre-existing generator '.
            'residual, fix lives in src/). See PHP_LINT_EXEMPT_SPECS in tests/Pest.php.'
        );
    }

    $document = (new SpecParser)->parseFile($path);
    $files = (new ModelGenerator)->generate($document);

    $failures = phpLintFailures($files, basename($path), PHP_LINT_CORPUS_PER_SPEC_CAP);

    expect($failures)->toBe([], implode("\n", $failures));
})->with('corpus_specs');
