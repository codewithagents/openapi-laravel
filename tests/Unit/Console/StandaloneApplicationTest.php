<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\StandaloneApplication;

$spec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_standalone_'.uniqid();

it('generates classes from --spec and --output', function () use ($spec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/CustomerData.php'))->toBeTrue()
        ->and(is_file($out.'/CustomerStatus.php'))->toBeTrue();
});

it('honours a custom --namespace', function () use ($spec, $tempOut) {
    $out = $tempOut();

    (new StandaloneApplication)->run(['bin', '--spec='.$spec(), '--output='.$out, '--namespace=Acme\\Dto']);

    expect(file_get_contents($out.'/CustomerData.php'))->toContain('namespace Acme\Dto;');
});

it('exits non-zero when required arguments are missing', function () {
    expect((new StandaloneApplication)->run(['bin']))->toBe(1);
});

it('exits non-zero when the spec cannot be read', function () use ($tempOut) {
    $exit = (new StandaloneApplication)->run(['bin', '--spec=/no/such.json', '--output='.$tempOut()]);

    expect($exit)->toBe(1);
});

it('generates controllers and routes from the server flags', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/controllers';
    $routesOut = $out.'/routes/api.generated.php';

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$out.'/data',
        '--controllers',
        '--controller-output='.$controllerOut,
        '--controller-namespace=Acme\\Http\\Controllers',
        '--routes',
        '--routes-output='.$routesOut,
    ]);

    expect($exit)->toBe(0)
        ->and(is_file($controllerOut.'/AbstractPetController.php'))->toBeTrue()
        ->and(file_get_contents($controllerOut.'/AbstractPetController.php'))->toContain('namespace Acme\Http\Controllers;')
        ->and(is_file($routesOut))->toBeTrue()
        ->and(file_get_contents($routesOut))->toContain('use Acme\Http\Controllers\PetController;');
});

it('exits non-zero when --controllers is set without --controller-output', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$tempOut(),
        '--controllers',
    ]);

    expect($exit)->toBe(1);
});

it('exits non-zero when --routes is set without --routes-output', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$tempOut(),
        '--routes',
    ]);

    expect($exit)->toBe(1);
});
