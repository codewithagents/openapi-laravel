<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\StandaloneApplication;

/*
 * The Laravel-convention method naming (issue #94, the only naming) through
 * the framework-free binary: clean CRUD methods come out conventional from a
 * plain run, generate and check stay in lockstep, and the retired
 * controllers.laravel_conventions config key fails loudly instead of being
 * silently ignored.
 */

$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_sa_conv_'.uniqid();

$writeConfig = function (array $config): string {
    $dir = sys_get_temp_dir().'/oal_sa_conv_cfg_'.uniqid();
    mkdir($dir, 0755, true);
    $path = $dir.'/openapi-laravel.json';
    file_put_contents($path, json_encode($config));

    return $path;
};

it('names clean CRUD methods conventionally by default', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out]);

    $controller = file_get_contents($out.'/Controllers/AbstractPetController.php');
    $routes = file_get_contents($out.'/routes.php');

    expect($exit)->toBe(0)
        ->and($controller)->toContain('abstract public function index(')
        ->and($controller)->toContain('abstract public function store(')
        ->and($controller)->toContain('abstract public function show(')
        ->and($controller)->toContain('abstract public function destroy(')
        ->and($routes)->toContain("[PetController::class, 'show'])->name('show')")
        ->and($routes)->not->toContain('listPets');
});

it('rejects the retired controllers.laravel_conventions config key with exit 2 and writes nothing', function () use ($serverSpec, $tempOut, $writeConfig) {
    $config = $writeConfig(['controllers' => ['laravel_conventions' => true]]);
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$config, '--spec='.$serverSpec(), '--output='.$out]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep over the conventional names', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $app = new StandaloneApplication;

    expect($app->run(['bin', '--spec='.$serverSpec(), '--output='.$out]))->toBe(0)
        ->and($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out]))->toBe(0);

    // A tampered controller registers as drift like any other owned file.
    $controller = $out.'/Controllers/AbstractPetController.php';
    file_put_contents($controller, file_get_contents($controller).' ');
    expect($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out]))->toBe(1);
});
