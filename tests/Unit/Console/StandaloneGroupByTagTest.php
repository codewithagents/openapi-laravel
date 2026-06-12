<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\StandaloneApplication;

/*
 * The tag-grouped data layout (issue #93, the only layout) through the
 * framework-free binary: per-tag directories and namespaces come out of a
 * plain run, generate and check stay in lockstep over the grouped tree, and
 * the retired output.group_by_tag config key fails loudly instead of being
 * silently ignored.
 */

$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_sa_group_'.uniqid();

$writeConfig = function (array $config): string {
    $dir = sys_get_temp_dir().'/oal_sa_group_cfg_'.uniqid();
    mkdir($dir, 0755, true);
    $path = $dir.'/openapi-laravel.json';
    file_put_contents($path, json_encode($config));

    return $path;
};

it('emits the grouped layout by default', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/Pet/PetData.php'))->toBeTrue()
        ->and(is_file($out.'/PetData.php'))->toBeFalse()
        ->and((string) file_get_contents($out.'/Pet/PetData.php'))->toContain('namespace App\Data\Pet;')
        ->and((string) file_get_contents($out.'/Controllers/AbstractPetController.php'))
        ->toContain('use App\Data\Pet\PetData;');
});

it('rejects the retired output.group_by_tag config key with exit 2 and writes nothing', function () use ($serverSpec, $tempOut, $writeConfig) {
    $config = $writeConfig(['output' => ['group_by_tag' => true]]);
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$config, '--spec='.$serverSpec(), '--output='.$out]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep over the grouped tree', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $app = new StandaloneApplication;

    expect($app->run(['bin', '--spec='.$serverSpec(), '--output='.$out]))->toBe(0)
        ->and($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out]))->toBe(0);

    // A tampered grouped file registers as drift like any other owned file.
    file_put_contents($out.'/Pet/PetData.php', file_get_contents($out.'/Pet/PetData.php').' ');
    expect($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out]))->toBe(1);
});
