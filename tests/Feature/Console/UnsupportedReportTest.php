<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Fidelity\FidelityReport;

/**
 * The fidelity report (the unsupported-construct artifact) on the artisan
 * surface: openapi:generate writes a byte-stable openapi-laravel.unsupported.json
 * through the shared GenerationPlanner, openapi:check drift-checks it like every
 * other generated file, and the --no-unsupported-report opt-out removes it from
 * BOTH generation and the checked set (a user who opts out and deletes the file
 * does not get a drift failure). output.unsupported_report_path keeps the
 * artifact out of the spec/output trees so it never pollutes the corpus gates.
 */
$fixture = fn (): string => __DIR__.'/../../Fixtures/fidelity/unsupported-constructs.yaml';
$supported = fn (): string => __DIR__.'/../../Fixtures/fidelity/fully-supported.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_fidelity_'.uniqid();

$configure = function (string $spec, string $out, string $reportPath): void {
    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Controllers');
    config()->set('openapi-laravel.routes.path', $out.'/routes.php');
    config()->set('openapi-laravel.output.unsupported_report_path', $reportPath);
};

it('writes a deterministic fidelity report on generate, byte-identical across runs', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);

    $this->artisan('openapi:generate')->assertSuccessful();
    expect(is_file($reportPath))->toBeTrue();
    $first = file_get_contents($reportPath);

    $decoded = json_decode($first, true);
    expect($decoded['generator'])->toBe('openapi-laravel')
        ->and(count($decoded['unsupported']))->toBeGreaterThan(0);

    $this->artisan('openapi:generate')->assertSuccessful();
    expect(file_get_contents($reportPath))->toBe($first);
});

it('emits an empty report for a fully supported spec', function () use ($supported, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($supported(), $out, $reportPath);

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(is_file($reportPath))->toBeTrue()
        ->and(json_decode(file_get_contents($reportPath), true)['unsupported'])->toBe([]);
});

it('check reports in sync when the report on disk matches', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);

    $this->artisan('openapi:generate')->assertSuccessful();
    $this->artisan('openapi:check')->assertExitCode(0);
});

it('check flags drift when the report on disk is stale', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);

    $this->artisan('openapi:generate')->assertSuccessful();
    file_put_contents($reportPath, "{\n    \"generator\": \"openapi-laravel\",\n    \"unsupported\": []\n}\n");

    $this->artisan('openapi:check')->assertExitCode(1);
});

it('check flags drift when the report is missing', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);

    $this->artisan('openapi:generate')->assertSuccessful();
    unlink($reportPath);

    $this->artisan('openapi:check')->assertExitCode(1);
});

it('--no-unsupported-report omits the file from generation', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);

    $this->artisan('openapi:generate', ['--no-unsupported-report' => true])->assertSuccessful();

    expect(is_file($reportPath))->toBeFalse();
});

it('--no-unsupported-report removes the file from the checked set (no drift on a deleted file)', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);

    // Generate everything except the report, then check with the same opt-out.
    $this->artisan('openapi:generate', ['--no-unsupported-report' => true])->assertSuccessful();
    expect(is_file($reportPath))->toBeFalse();

    $this->artisan('openapi:check', ['--no-unsupported-report' => true])->assertExitCode(0);
});

it('the config opt-out (output.unsupported_report=false) also omits the file from both surfaces', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $reportPath = $out.'.unsupported.json';
    $configure($fixture(), $out, $reportPath);
    config()->set('openapi-laravel.output.unsupported_report', false);

    $this->artisan('openapi:generate')->assertSuccessful();
    expect(is_file($reportPath))->toBeFalse();

    $this->artisan('openapi:check')->assertExitCode(0);
});

it('rejects --unsupported-report combined with --no-unsupported-report (exit 2)', function () use ($fixture, $tempOut, $configure) {
    $out = $tempOut();
    $configure($fixture(), $out, $out.'.unsupported.json');

    $this->artisan('openapi:generate', ['--unsupported-report' => true, '--no-unsupported-report' => true])
        ->assertExitCode(2);
});

it('uses the FidelityReport filename constant for the default path', function () {
    expect(FidelityReport::FILENAME)->toBe('openapi-laravel.unsupported.json');
});
