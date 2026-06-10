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
