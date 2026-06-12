<?php

declare(strict_types=1);

$spec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$bin = fn (): string => __DIR__.'/../../../bin/openapi-laravel';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_standalone_scaffold_'.uniqid();

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

it('scaffold writes one concrete stub per controller into <output>/Controllers', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$exit, $output] = $run($bin(), ['scaffold', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Scaffolded 2 controller stubs into '.$out.'/Controllers')
        ->and(is_file($out.'/Controllers/PetController.php'))->toBeTrue()
        ->and(is_file($out.'/Controllers/UntaggedController.php'))->toBeTrue()
        ->and((string) file_get_contents($out.'/Controllers/PetController.php'))
        ->toContain('final class PetController extends AbstractPetController');
});

it('scaffold writes no Data classes, no abstracts, and no routes file', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$exit] = $run($bin(), ['scaffold', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeFalse()
        ->and(is_file($out.'/Controllers/AbstractPetController.php'))->toBeFalse()
        ->and(is_file($out.'/routes.php'))->toBeFalse();
});

it('scaffold skips existing stubs and reports them, never overwriting', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();
    mkdir($out.'/Controllers', 0755, true);
    file_put_contents($out.'/Controllers/PetController.php', '<?php // mine');

    [$exit, $output] = $run($bin(), ['scaffold', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Scaffolded 1 controller stub into '.$out.'/Controllers')
        ->and($output)->toContain('Skipped 1 existing file (stubs are generated once and never overwritten): PetController.php')
        ->and(file_get_contents($out.'/Controllers/PetController.php'))->toBe('<?php // mine');
});

it('generate then scaffold then check exits 0: the drift gate never sees a stub', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$genExit] = $run($bin(), ['--spec='.$spec(), '--output='.$out]);
    expect($genExit)->toBe(0);

    [$scaffoldExit] = $run($bin(), ['scaffold', '--spec='.$spec(), '--output='.$out]);
    expect($scaffoldExit)->toBe(0);

    [$checkExit, $checkOutput] = $run($bin(), ['check', '--spec='.$spec(), '--output='.$out]);
    expect($checkExit)->toBe(0)
        ->and($checkOutput)->toContain('Generated code is in sync with the spec.');
});

it('scaffold exits 1 with a clear message when controllers are disabled', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$exit, $output] = $run($bin(), ['scaffold', '--spec='.$spec(), '--output='.$out, '--no-controllers']);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Controller stubs extend the generated abstract controllers, but controllers are disabled.')
        ->and(is_dir($out))->toBeFalse();
});

it('scaffold exits 2 on conflicting --controllers and --no-controllers', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$exit, $output] = $run($bin(), ['scaffold', '--spec='.$spec(), '--output='.$out, '--controllers', '--no-controllers']);

    expect($exit)->toBe(2)
        ->and($output)->toContain('--controllers and --no-controllers cannot be combined.')
        ->and(is_dir($out))->toBeFalse();
});

it('scaffold honours --controller-output and --controller-namespace', function () use ($spec, $bin, $tempOut, $run) {
    $out = $tempOut();

    [$exit] = $run($bin(), [
        'scaffold',
        '--spec='.$spec(),
        '--output='.$out,
        '--controller-output='.$out.'/Api',
        '--controller-namespace=Acme\\Api',
    ]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/Api/PetController.php'))->toBeTrue()
        ->and((string) file_get_contents($out.'/Api/PetController.php'))->toContain('namespace Acme\Api;');
});

it('scaffold reports cleanly when the spec declares no operations', function () use ($bin, $tempOut, $run) {
    $spec = $tempOut().'.json';
    file_put_contents($spec, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'No operations', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => ['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]],
    ]));
    $out = $tempOut();

    [$exit, $output] = $run($bin(), ['scaffold', '--spec='.$spec, '--output='.$out]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('No controller stubs to scaffold: no operations were planned')
        ->and(is_dir($out.'/Controllers'))->toBeFalse();
});
