<?php

declare(strict_types=1);

$spec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$bin = fn (): string => __DIR__.'/../../../bin/openapi-laravel';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_standalone_check_'.uniqid();

/**
 * Runs the real vendor/bin/openapi-laravel binary as a subprocess so the test
 * exercises STDOUT/STDERR and the process exit code exactly as CI would.
 *
 * @param  list<string>  $args
 * @return array{0: int, 1: string}
 */
$run = function (string $bin, array $args): array {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($bin);
    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }
    $command .= ' 2>&1';

    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    return [$exit, implode("\n", $output)];
};

it('check exits 0 and reports in sync after generate', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$genExit] = $run($bin(), ['--spec='.$spec(), '--output='.$out]);
    expect($genExit)->toBe(0);

    [$exit, $output] = $run($bin(), ['check', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Generated code is in sync with the spec.');
});

it('check exits 1 and lists a changed file', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();
    $run($bin(), ['--spec='.$spec(), '--output='.$out]);

    $path = $out.'/CustomerData.php';
    file_put_contents($path, file_get_contents($path).' ');

    [$exit, $output] = $run($bin(), ['check', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Drift detected in 1 file(s):')
        ->and($output)->toContain('[changed] '.$path);
});

it('check exits 1 and lists a missing file', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();
    $run($bin(), ['--spec='.$spec(), '--output='.$out]);

    $path = $out.'/CustomerData.php';
    unlink($path);

    [$exit, $output] = $run($bin(), ['check', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('[missing] '.$path);
});

it('check prints a diff with --diff', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();
    $run($bin(), ['--spec='.$spec(), '--output='.$out]);

    $path = $out.'/CustomerData.php';
    file_put_contents($path, str_replace('final class', 'final  class', file_get_contents($path)));

    [$exit, $output] = $run($bin(), ['check', '--diff', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('-final class CustomerData extends Data')
        ->and($output)->toContain('+final  class CustomerData extends Data');
});

it('check surfaces the 3.2 best-effort warnings exactly like generate (#103)', function () use ($bin, $tempOut, $run) {
    $spec = __DIR__.'/../../Fixtures/edge/openapi-3.2-constructs.yaml';
    $out = $tempOut();

    // Generate accepts the 3.2 spec best-effort (exit 0) and warns loudly.
    [$genExit, $genOutput] = $run($bin(), ['--spec='.$spec, '--output='.$out]);
    expect($genExit)->toBe(0)
        ->and($genOutput)->toContain('OpenAPI 3.2 is not fully supported yet')
        ->and($genOutput)->toContain('`query` operation at paths./things was dropped')
        ->and($genOutput)->toContain('`additionalOperations` at paths./things were dropped')
        ->and($genOutput)->toContain('`itemSchema` at paths./things/stream.get.responses.200.content.application/jsonl was dropped');

    // The drift gate behaves consistently: in sync (exit 0), same warnings.
    [$exit, $output] = $run($bin(), ['check', '--spec='.$spec, '--output='.$out]);
    expect($exit)->toBe(0)
        ->and($output)->toContain('Generated code is in sync with the spec.')
        ->and($output)->toContain('OpenAPI 3.2 is not fully supported yet')
        ->and($output)->toContain('`query` operation at paths./things was dropped')
        ->and($output)->toContain('`additionalOperations` at paths./things were dropped')
        ->and($output)->toContain('`itemSchema` at paths./things/stream.get.responses.200.content.application/jsonl was dropped');
});

it('check rejects an unsupported OpenAPI version with a clear error (#103)', function () use ($bin, $tempOut, $run) {
    $spec = sys_get_temp_dir().'/oal_standalone_check_v4_'.uniqid().'.json';
    file_put_contents($spec, '{"openapi":"4.0.0","info":{"title":"T","version":"1.0.0"},"paths":{}}');

    [$exit, $output] = $run($bin(), ['check', '--spec='.$spec, '--output='.$tempOut()]);

    expect($exit)->toBe(2)
        ->and($output)->toContain("Unsupported OpenAPI version '4.0.0'")
        ->and($output)->toContain('Supported versions: OpenAPI 3.0.x and 3.1.x');
});

it('check exits 2 on a spec error', function () use ($bin, $tempOut, $run) {
    [$exit] = $run($bin(), ['check', '--spec=/no/such.json', '--output='.$tempOut()]);

    expect($exit)->toBe(2);
});

it('check exits 2 when required arguments are missing', function () use ($bin, $run) {
    [$exit] = $run($bin(), ['check']);

    expect($exit)->toBe(2);
});
