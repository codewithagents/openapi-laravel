<?php

declare(strict_types=1);
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;

/*
 * Feature tests boot a Testbench Laravel app (service provider, artisan command).
 * Unit tests (Parser, Naming, Emitter) run as plain PHP, no container needed.
 */
uses(TestCase::class)->in('Feature');

/*
 * The real-world spec corpus, shared by the parse and generate gates.
 */
dataset('corpus_specs', function () {
    $files = glob(__DIR__.'/Fixtures/specs/*.{json,yaml,yml}', GLOB_BRACE) ?: [];

    $cases = [];
    foreach ($files as $file) {
        $cases[basename($file)] = [$file];
    }

    return $cases;
});
