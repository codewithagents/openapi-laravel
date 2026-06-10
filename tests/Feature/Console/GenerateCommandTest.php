<?php

declare(strict_types=1);

$customerSpec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_cmd_'.uniqid();

it('generates classes from the configured spec', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(is_file($out.'/CustomerData.php'))->toBeTrue()
        ->and(is_file($out.'/CustomerStatus.php'))->toBeTrue()
        ->and(is_file($out.'/TagData.php'))->toBeTrue()
        ->and(is_file($out.'/CustomerAddressData.php'))->toBeTrue();
});

it('honours the --spec and --output options', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    expect(is_file($out.'/CustomerData.php'))->toBeTrue();
});

it('fails clearly when the spec cannot be read', function () use ($tempOut) {
    config()->set('openapi-laravel.spec', '/no/such/spec.json');
    config()->set('openapi-laravel.output.path', $tempOut());

    $this->artisan('openapi:generate')->assertFailed();
});

it('generates abstract controllers and a routes file when asked', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.controllers.namespace', 'App\\Http\\Controllers\\Api');
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--controllers' => true,
        '--routes' => true,
    ])->assertSuccessful();

    expect(is_file($controllerOut.'/AbstractPetController.php'))->toBeTrue()
        ->and(is_file($routesOut))->toBeTrue()
        ->and(file_get_contents($routesOut))->toContain("Route::get('/pets'");
});

it('never prunes concrete controllers, only overwrites the Abstract files', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    mkdir($controllerOut, 0755, true);
    file_put_contents($controllerOut.'/PetController.php', '<?php // hand-written');

    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--controllers' => true,
    ])->assertSuccessful();

    expect(is_file($controllerOut.'/PetController.php'))->toBeTrue()
        ->and(file_get_contents($controllerOut.'/PetController.php'))->toBe('<?php // hand-written')
        ->and(is_file($controllerOut.'/AbstractPetController.php'))->toBeTrue();
});

it('prunes stale files when asked', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    mkdir($out, 0755, true);
    file_put_contents($out.'/StaleData.php', '<?php // stale');

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
        '--prune' => true,
    ])->assertSuccessful();

    expect(is_file($out.'/StaleData.php'))->toBeFalse()
        ->and(is_file($out.'/CustomerData.php'))->toBeTrue();
});
