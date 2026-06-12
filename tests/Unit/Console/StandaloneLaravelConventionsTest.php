<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\StandaloneApplication;

/*
 * The opt-in Laravel-convention method naming (issue #94) through the
 * framework-free binary: the --laravel-conventions flag, the mirrored
 * controllers.laravel_conventions JSON config key, flag-over-config
 * precedence, the conflicting-flags error, and generate/check lockstep.
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

it('names clean CRUD methods conventionally with --laravel-conventions', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out, '--laravel-conventions']);

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

it('keeps operationId-derived names by default', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(file_get_contents($out.'/Controllers/AbstractPetController.php'))
        ->toContain('abstract public function listPets(')
        ->not->toContain('function index(');
});

it('honours controllers.laravel_conventions from the JSON config, with --no-laravel-conventions overriding it', function () use ($serverSpec, $tempOut, $writeConfig) {
    $config = $writeConfig(['controllers' => ['laravel_conventions' => true]]);

    // Config alone turns the conventions on.
    $configOut = $tempOut();
    $exit = (new StandaloneApplication)->run(['bin', '--config='.$config, '--spec='.$serverSpec(), '--output='.$configOut]);
    expect($exit)->toBe(0)
        ->and(file_get_contents($configOut.'/Controllers/AbstractPetController.php'))
        ->toContain('abstract public function index(');

    // The disable flag beats the enabling config.
    $flagOut = $tempOut();
    $exit = (new StandaloneApplication)->run(['bin', '--config='.$config, '--spec='.$serverSpec(), '--output='.$flagOut, '--no-laravel-conventions']);
    expect($exit)->toBe(0)
        ->and(file_get_contents($flagOut.'/Controllers/AbstractPetController.php'))
        ->toContain('abstract public function listPets(')
        ->not->toContain('function index(');
});

it('rejects --laravel-conventions combined with --no-laravel-conventions with exit 2 and writes nothing', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec(), '--output='.$out, '--laravel-conventions', '--no-laravel-conventions']);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep: check needs the same flag to be in sync', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $app = new StandaloneApplication;

    expect($app->run(['bin', '--spec='.$serverSpec(), '--output='.$out, '--laravel-conventions']))->toBe(0)
        // Same flag: byte-for-byte in sync.
        ->and($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out, '--laravel-conventions']))->toBe(0)
        // Without the flag the planner computes operationId-derived names, so
        // the conventional files on disk register as drift.
        ->and($app->run(['bin', 'check', '--spec='.$serverSpec(), '--output='.$out]))->toBe(1);
});
