<?php

declare(strict_types=1);

/**
 * The two issue #83 extension points on the artisan surface:
 * controllers.base_class (the FQCN generated abstract controllers extend) and
 * output.validation_trait (the user-owned trait every generated Data class
 * pulls in for custom validation messages/attributes). Both are config-only
 * keys, validated as legal FQCNs before any file is written (a hostile value
 * is a configuration error, exit 2), and both flow through the shared
 * GenerationPlanner so generate and check stay in lockstep.
 */
$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_ext_'.uniqid();

$configureServerRun = function (string $spec, string $out): void {
    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Controllers');
    config()->set('openapi-laravel.routes.path', $out.'/routes.php');
};

it('extends the configured controllers.base_class in every generated abstract controller', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);
    config()->set('openapi-laravel.controllers.base_class', 'App\\Http\\Controllers\\Controller');

    $this->artisan('openapi:generate')->assertSuccessful();

    $code = file_get_contents($out.'/Controllers/AbstractPetController.php');
    expect($code)->toContain('use App\Http\Controllers\Controller;')
        ->and($code)->toContain('abstract class AbstractPetController extends Controller');
});

it('keeps the default output base-class-free when controllers.base_class is null', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(file_get_contents($out.'/Controllers/AbstractPetController.php'))->not->toContain('extends');
});

it('treats a blank controllers.base_class as not set', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);
    config()->set('openapi-laravel.controllers.base_class', '   ');

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(file_get_contents($out.'/Controllers/AbstractPetController.php'))->not->toContain('extends');
});

it('rejects a hostile controllers.base_class before writing anything (exit 2)', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);
    config()->set('openapi-laravel.controllers.base_class', "App\\Evil'); system('x'); //");

    $this->artisan('openapi:generate')->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('adds the configured output.validation_trait to every generated Data class', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);
    config()->set('openapi-laravel.output.validation_trait', 'App\\Support\\ApiMessages');

    $this->artisan('openapi:generate')->assertSuccessful();

    $code = file_get_contents($out.'/PetData.php');
    expect($code)->toContain('use App\Support\ApiMessages;')
        ->and($code)->toContain('    use ApiMessages;');
});

it('rejects a hostile output.validation_trait before writing anything (exit 2)', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);
    config()->set('openapi-laravel.output.validation_trait', 'App\\Support\\Api Messages');

    $this->artisan('openapi:generate')->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep for both extension points', function () use ($serverSpec, $tempOut, $configureServerRun) {
    $out = $tempOut();
    $configureServerRun($serverSpec(), $out);
    config()->set('openapi-laravel.controllers.base_class', 'App\\Http\\Controllers\\Controller');
    config()->set('openapi-laravel.output.validation_trait', 'App\\Support\\ApiMessages');

    $this->artisan('openapi:generate')->assertSuccessful();
    // The shared planner computes the same plan for check, so a fresh
    // generate is immediately in sync.
    $this->artisan('openapi:check')->assertSuccessful();

    // Changing the configured base class makes the on-disk controllers stale:
    // check must flag the drift (exit 1), proving the option is part of the
    // planned content, not a write-time afterthought.
    config()->set('openapi-laravel.controllers.base_class', 'App\\Http\\Controllers\\OtherBase');
    $this->artisan('openapi:check')->assertExitCode(1);
});
