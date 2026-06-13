<?php

declare(strict_types=1);
use Symfony\Component\Console\Exception\InvalidOptionException;

/*
 * The Laravel-convention method naming (issue #94) is the generator's only
 * naming: clean RESTful operations come out as index/show/store/update/destroy
 * (method AND route name) from a plain `openapi:generate` with no flag and no
 * config key. These tests pin the conventional naming as the default, the
 * fallback rules, and generate/check lockstep through the shared planner.
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

it('names clean CRUD methods conventionally by default, route names following', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut, $routesOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
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
        ->and($routes)->toContain("Route::get('/pets/{petId}', [PetController::class, 'show'])->name('show')->whereNumber('petId');")
        ->and($routes)->toContain("Route::delete('/pets/{petId}', [PetController::class, 'destroy'])")
        ->and($routes)->not->toContain('listPets');
});

it('makes ambiguous claimants fall back to the operationId-derived names', function () use ($tempOut, $configurePaths) {
    // Two collection GETs in one controller both claim `index`, so BOTH keep
    // their operationId-derived name; the query-parameters fixture has
    // exactly that shape (/search and /widgets under the widget tag).
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => __DIR__.'/../../Fixtures/server/query-parameters.yaml',
        '--output' => $out,
    ])->assertSuccessful();

    $controller = file_get_contents($controllerOut.'/AbstractWidgetController.php');

    expect($controller)->toContain('abstract public function listWidgets(')
        ->and($controller)->toContain('abstract public function searchWidgets(')
        ->and($controller)->not->toContain('function index(')
        // The unambiguous item GET still takes the conventional name.
        ->and($controller)->toContain('abstract public function show(');
});

it('produces byte-identical output across two runs (determinism)', function () use ($serverSpec, $tempOut, $configurePaths) {
    $firstOut = $tempOut();
    [$firstControllers, $firstRoutes] = $configurePaths($firstOut);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $firstOut])->assertSuccessful();

    $secondOut = $tempOut();
    [$secondControllers, $secondRoutes] = $configurePaths($secondOut);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $secondOut])->assertSuccessful();

    expect(file_get_contents($secondControllers.'/AbstractPetController.php'))
        ->toBe(file_get_contents($firstControllers.'/AbstractPetController.php'))
        ->and(file_get_contents($secondRoutes))->toBe(file_get_contents($firstRoutes));
});

it('rejects the removed --laravel-conventions flag instead of silently accepting it', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    expect(fn () => $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--laravel-conventions' => true,
    ]))->toThrow(InvalidOptionException::class);
});

it('keeps generate and check in lockstep over the conventional names', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);
    config()->set('openapi-laravel.spec', $serverSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    $this->artisan('openapi:check')
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);

    // A tampered controller registers as drift like any other owned file.
    $controller = $controllerOut.'/AbstractPetController.php';
    file_put_contents($controller, file_get_contents($controller).' ');
    $this->artisan('openapi:check')->assertExitCode(1);
});
