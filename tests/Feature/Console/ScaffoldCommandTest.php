<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$petstoreSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_scaffold_'.uniqid();

/**
 * Points the published config at a fresh temp tree and returns its paths.
 *
 * @return array{out: string, controllers: string, routes: string}
 */
$configure = function (string $out): array {
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.controllers.namespace', 'App\\Http\\Controllers\\Api');
    config()->set('openapi-laravel.routes.path', $routesOut);

    return ['out' => $out, 'controllers' => $controllerOut, 'routes' => $routesOut];
};

it('creates one concrete stub per controller the routes file references', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:generate', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();
    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();

    $stub = $paths['controllers'].'/PetController.php';
    expect(is_file($stub))->toBeTrue()
        ->and(is_file($paths['controllers'].'/UntaggedController.php'))->toBeTrue()
        ->and(file_get_contents($stub))->toContain('namespace App\Http\Controllers\Api;')
        ->and(file_get_contents($stub))->toContain('final class PetController extends AbstractPetController')
        ->and(file_get_contents($stub))->toContain("throw new LogicException('Not implemented: listPets.');");

    // Every concrete controller class the generated routes file imports now
    // exists as a file, which is the whole point of the command (issue #78).
    preg_match_all('/use App\\\\Http\\\\Controllers\\\\Api\\\\(\w+);/', (string) file_get_contents($paths['routes']), $matches);
    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $controller) {
        expect(is_file($paths['controllers'].'/'.$controller.'.php'))->toBeTrue("routes reference {$controller} but no stub exists");
    }
});

it('emits stubs that pass php -l', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();

    foreach (glob($paths['controllers'].'/*.php') ?: [] as $file) {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "php -l failed for {$file}:\n".implode("\n", $output));
    }
});

it('never overwrites an existing stub: re-running skips the user-owned file', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();

    $stub = $paths['controllers'].'/PetController.php';
    file_put_contents($stub, '<?php // implemented by the user');

    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    expect(file_get_contents($stub))->toBe('<?php // implemented by the user');
});

it('creates only the missing stubs when some already exist', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());
    mkdir($paths['controllers'], 0755, true);
    file_put_contents($paths['controllers'].'/PetController.php', '<?php // mine');

    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();

    expect(file_get_contents($paths['controllers'].'/PetController.php'))->toBe('<?php // mine')
        ->and(is_file($paths['controllers'].'/UntaggedController.php'))->toBeTrue();
});

it('is ignored by the drift gate: check stays green with stubs present and even edited (issue #78)', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:generate', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();
    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertSuccessful();

    // Stubs on disk: check must not flag them (they are user-owned, not planned).
    $this->artisan('openapi:check', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertExitCode(0);

    // A user editing a stub is normal life, never drift.
    file_put_contents($paths['controllers'].'/PetController.php', '<?php // user implementation');
    $this->artisan('openapi:check', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertExitCode(0);
});

it('restricts the stub set to the subset selection, matching generate exactly', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:scaffold', [
        '--spec' => $petstoreSpec(),
        '--output' => $paths['out'],
        '--only-tags' => 'pet',
    ])->assertSuccessful();

    expect(is_file($paths['controllers'].'/PetController.php'))->toBeTrue()
        ->and(is_file($paths['controllers'].'/UntaggedController.php'))->toBeFalse();
});

it('fails loudly when controllers are disabled: stubs would extend classes that are never generated', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());
    config()->set('openapi-laravel.controllers.enabled', false);

    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $paths['out']])->assertFailed();

    expect(is_dir($paths['controllers']))->toBeFalse();
});

it('lets --controllers force scaffolding over a config that disables controllers', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());
    config()->set('openapi-laravel.controllers.enabled', false);

    $this->artisan('openapi:scaffold', [
        '--spec' => $petstoreSpec(),
        '--output' => $paths['out'],
        '--controllers' => true,
    ])->assertSuccessful();

    expect(is_file($paths['controllers'].'/PetController.php'))->toBeTrue();
});

it('rejects --controllers combined with --no-controllers with exit 2 and writes nothing', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:scaffold', [
        '--spec' => $petstoreSpec(),
        '--output' => $paths['out'],
        '--controllers' => true,
        '--no-controllers' => true,
    ])->assertExitCode(2);

    expect(is_dir($paths['out']))->toBeFalse();
});

it('boots: generate + scaffold yields a route table whose dispatch reaches the stub (issue #78)', function () use ($petstoreSpec, $tempOut) {
    // A dedicated namespace pair so the loaded classes can never collide with
    // the other suites that load the same petstore fixture under App\Data.
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.output.namespace', 'ScaffoldBoot\\Data');
    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.controllers.namespace', 'ScaffoldBoot\\Http\\Controllers\\Api');
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', ['--spec' => $petstoreSpec(), '--output' => $out])->assertSuccessful();
    $this->artisan('openapi:scaffold', ['--spec' => $petstoreSpec(), '--output' => $out])->assertSuccessful();

    // Load everything the way an app's autoloader would: Data classes, the
    // inlined support classes, then controllers (sorted, so each Abstract*
    // parent is defined before the concrete stub that extends it).
    foreach ([$out, $out.'/Support', $controllerOut] as $dir) {
        $files = glob($dir.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }
    }

    // The generated routes file registers against the CONCRETE classes: with
    // the stubs in place this must not fatal, which is the acceptance
    // criterion "fresh project boots immediately after generate + scaffold".
    Route::middleware('api')->group(function () use ($routesOut): void {
        require $routesOut;
    });

    $this->withoutExceptionHandling();

    // Dispatch a real request through the booted table: it reaches the stub
    // and surfaces the explicit placeholder, not a missing-class fatal.
    expect(fn () => $this->get('/pets/7'))
        ->toThrow(LogicException::class, 'Not implemented: getPetById.');
});

it('follows --laravel-conventions so stub signatures match the abstracts (#94 lockstep)', function () use ($petstoreSpec, $tempOut, $configure) {
    $paths = $configure($tempOut());

    $this->artisan('openapi:generate', [
        '--spec' => $petstoreSpec(),
        '--output' => $paths['out'],
        '--laravel-conventions' => true,
    ])->assertSuccessful();
    $this->artisan('openapi:scaffold', [
        '--spec' => $petstoreSpec(),
        '--output' => $paths['out'],
        '--laravel-conventions' => true,
    ])->assertSuccessful();

    $stub = (string) file_get_contents($paths['controllers'].'/PetController.php');

    // The stub implements the conventional names the abstract declares; an
    // operationId-derived leftover would be a PHP fatal at class load.
    expect($stub)->toContain('public function show(int $petId): PetData')
        ->and($stub)->toContain("throw new LogicException('Not implemented: show.');")
        ->and($stub)->not->toContain('getPetById');
});
