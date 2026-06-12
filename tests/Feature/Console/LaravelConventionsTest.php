<?php

declare(strict_types=1);

/*
 * The opt-in Laravel-convention method naming (issue #94) through the artisan
 * surface: the --laravel-conventions flag, the mirrored
 * controllers.laravel_conventions config key, the strict flag-over-config
 * precedence, the conflicting-flags error, the default-unchanged guarantee,
 * and generate/check lockstep through the shared planner.
 */

$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_conv_'.uniqid();

$configurePaths = function (string $out): array {
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';
    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.path', $routesOut);

    return [$controllerOut, $routesOut];
};

it('names clean CRUD methods conventionally with --laravel-conventions, route names following', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut, $routesOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--laravel-conventions' => true,
    ])->assertSuccessful();

    $controller = file_get_contents($controllerOut.'/AbstractPetController.php');
    $routes = file_get_contents($routesOut);

    // GET /pets -> index, POST /pets -> store, GET /pets/{petId} -> show,
    // DELETE /pets/{petId} -> destroy; the operationId-derived names are gone.
    // The untagged GET /health is a collection GET too, so it owns `index` in
    // its own controller; route names are global and path-sorted (/health
    // before /pets), so the pet index route is suffixed to index_2.
    expect($controller)->toContain('abstract public function index(')
        ->and($controller)->toContain('abstract public function store(')
        ->and($controller)->toContain('abstract public function show(')
        ->and($controller)->toContain('abstract public function destroy(')
        ->and($controller)->not->toContain('listPets')
        ->and($routes)->toContain("Route::get('/health', [UntaggedController::class, 'index'])->name('index');")
        ->and($routes)->toContain("Route::get('/pets', [PetController::class, 'index'])->name('index_2');")
        ->and($routes)->toContain("Route::post('/pets', [PetController::class, 'store'])")
        ->and($routes)->toContain("Route::get('/pets/{petId}', [PetController::class, 'show'])->name('show');")
        ->and($routes)->toContain("Route::delete('/pets/{petId}', [PetController::class, 'destroy'])")
        ->and($routes)->not->toContain('listPets');
});

it('keeps the default output unchanged when the flag is absent', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut, $routesOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    $controller = file_get_contents($controllerOut.'/AbstractPetController.php');
    $routes = file_get_contents($routesOut);

    expect($controller)->toContain('abstract public function listPets(')
        ->and($controller)->not->toContain('function index(')
        ->and($routes)->toContain("->name('listPets');")
        ->and($routes)->not->toContain("'index'");
});

it('produces byte-identical output for a default run and an explicit --no-laravel-conventions run', function () use ($serverSpec, $tempOut, $configurePaths) {
    $defaultOut = $tempOut();
    [$defaultControllers, $defaultRoutes] = $configurePaths($defaultOut);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $defaultOut])->assertSuccessful();

    $offOut = $tempOut();
    [$offControllers, $offRoutes] = $configurePaths($offOut);
    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $offOut,
        '--no-laravel-conventions' => true,
    ])->assertSuccessful();

    expect(file_get_contents($offControllers.'/AbstractPetController.php'))
        ->toBe(file_get_contents($defaultControllers.'/AbstractPetController.php'))
        ->and(file_get_contents($offRoutes))->toBe(file_get_contents($defaultRoutes));
});

it('lets the controllers.laravel_conventions config key enable the conventions without a flag', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);
    config()->set('openapi-laravel.controllers.laravel_conventions', true);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    expect(file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('abstract public function index(');
});

it('lets --no-laravel-conventions override a config that enables the conventions', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);
    config()->set('openapi-laravel.controllers.laravel_conventions', true);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--no-laravel-conventions' => true,
    ])->assertSuccessful();

    expect(file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('abstract public function listPets(')
        ->and(file_get_contents($controllerOut.'/AbstractPetController.php'))->not->toContain('function index(');
});

it('rejects --laravel-conventions combined with --no-laravel-conventions with exit 2 and writes nothing', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--laravel-conventions' => true,
        '--no-laravel-conventions' => true,
    ])->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep: check needs the same flag to be in sync', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.spec', $serverSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate', ['--laravel-conventions' => true])->assertSuccessful();

    // Same flag: byte-for-byte in sync.
    $this->artisan('openapi:check', ['--laravel-conventions' => true])
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);

    // Without the flag the planner computes operationId-derived names, so the
    // conventional files on disk register as drift.
    $this->artisan('openapi:check')->assertExitCode(1);
});

it('rejects the conflicting flag pair on openapi:check with exit 2', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.spec', $serverSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:check', [
        '--laravel-conventions' => true,
        '--no-laravel-conventions' => true,
    ])->assertExitCode(2);
});
