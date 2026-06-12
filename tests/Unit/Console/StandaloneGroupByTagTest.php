<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\StandaloneApplication;

/*
 * The opt-in tag-grouped data layout (issue #93) through the framework-free
 * binary: the --group-by-tag flag, the mirrored output.group_by_tag JSON
 * config key, flag-over-config precedence, the conflicting-flags error, and
 * generate/check lockstep.
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

it('emits the grouped layout with --group-by-tag', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out, '--group-by-tag']);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/Pet/PetData.php'))->toBeTrue()
        ->and(is_file($out.'/PetData.php'))->toBeFalse()
        ->and((string) file_get_contents($out.'/Pet/PetData.php'))->toContain('namespace App\Data\Pet;')
        ->and((string) file_get_contents($out.'/Controllers/AbstractPetController.php'))
        ->toContain('use App\Data\Pet\PetData;');
});

it('keeps the flat layout by default', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_dir($out.'/Pet'))->toBeFalse();
});

it('honours output.group_by_tag from the JSON config, with --no-group-by-tag overriding it', function () use ($serverSpec, $tempOut, $writeConfig) {
    $config = $writeConfig(['output' => ['group_by_tag' => true]]);

    // Config alone turns the grouped layout on.
    $configOut = $tempOut();
    $exit = (new StandaloneApplication)->run(['bin', '--config='.$config, '--spec='.$serverSpec(), '--output='.$configOut]);
    expect($exit)->toBe(0)
        ->and(is_file($configOut.'/Pet/PetData.php'))->toBeTrue();

    // The disable flag beats the enabling config.
    $flagOut = $tempOut();
    $exit = (new StandaloneApplication)->run(['bin', '--config='.$config, '--spec='.$serverSpec(), '--output='.$flagOut, '--no-group-by-tag']);
    expect($exit)->toBe(0)
        ->and(is_file($flagOut.'/PetData.php'))->toBeTrue()
        ->and(is_dir($flagOut.'/Pet'))->toBeFalse();
});

it('rejects --group-by-tag combined with --no-group-by-tag with exit 2 and writes nothing', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out, '--group-by-tag', '--no-group-by-tag']);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep: check needs the same flag to be in sync', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $app = new StandaloneApplication;

    expect($app->run(['bin', '--spec='.$serverSpec(), '--output='.$out, '--group-by-tag']))->toBe(0)
        // Same flag: byte-for-byte in sync, grouped subdirectories included.
        ->and($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out, '--group-by-tag']))->toBe(0)
        // Without the flag the planner computes the flat layout, so the
        // grouped tree on disk registers as drift (flat paths missing).
        ->and($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out]))->toBe(1);
});
