<?php

declare(strict_types=1);

$customerSpec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_check_'.uniqid();

it('reports in sync after generating into the output', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    $this->artisan('openapi:check')
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);
});

it('reports drift when a generated file is tampered', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    // Flip a single byte so the content differs but the file still exists.
    $path = $out.'/CustomerData.php';
    file_put_contents($path, file_get_contents($path).' ');

    $this->artisan('openapi:check')
        ->expectsOutputToContain('Drift detected in 1 file(s):')
        ->expectsOutputToContain('[changed] '.$path)
        ->assertExitCode(1);
});

it('reports a deleted generated file as missing', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    $path = $out.'/CustomerData.php';
    unlink($path);

    $this->artisan('openapi:check')
        ->expectsOutputToContain('[missing] '.$path)
        ->assertExitCode(1);
});

it('detects drift when the spec gains a property but the code is not regenerated', function () use ($tempOut) {
    $out = $tempOut();

    // A minimal spec, generated to disk.
    $specV1 = $tempOut().'.json';
    file_put_contents($specV1, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Drift', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Widget' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string']],
            ],
        ]],
    ]));

    config()->set('openapi-laravel.spec', $specV1);
    config()->set('openapi-laravel.output.path', $out);
    $this->artisan('openapi:generate')->assertSuccessful();

    // The spec now grows a property; the committed code is stale.
    $specV2 = $tempOut().'.json';
    file_put_contents($specV2, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Drift', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Widget' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ],
        ]],
    ]));

    config()->set('openapi-laravel.spec', $specV2);

    $this->artisan('openapi:check')
        ->expectsOutputToContain('[changed] '.$out.'/WidgetData.php')
        ->assertExitCode(1);
});

it('prints a unified diff for a changed file with --diff', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    $path = $out.'/CustomerData.php';
    file_put_contents($path, str_replace('final class', 'final  class', file_get_contents($path)));

    $this->artisan('openapi:check', ['--diff' => true])
        ->expectsOutputToContain('-final class CustomerData extends Data')
        ->expectsOutputToContain('+final  class CustomerData extends Data')
        ->assertExitCode(1);
});

it('checks generated controllers and the routes file by default', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.controllers.namespace', 'App\\Http\\Controllers\\Api');
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    $this->artisan('openapi:check', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertExitCode(0);

    // Tamper the routes file: it must now read as drifted, no flags needed.
    file_put_contents($routesOut, file_get_contents($routesOut)."\n// edited\n");

    $this->artisan('openapi:check', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])
        ->expectsOutputToContain('[changed] '.$routesOut)
        ->assertExitCode(1);
});

it('skips the scaffold in check with --no-controllers and --no-routes', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    config()->set('openapi-laravel.controllers.path', $out.'/Http/Controllers/Api');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    // Generate models only; a default check would flag the scaffold as missing.
    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--no-controllers' => true,
        '--no-routes' => true,
    ])->assertSuccessful();

    $this->artisan('openapi:check', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--no-controllers' => true,
        '--no-routes' => true,
    ])->assertExitCode(0);

    $this->artisan('openapi:check', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertExitCode(1);
});

it('never flags the user hand-written concrete controllers', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--controllers' => true,
    ])->assertSuccessful();

    // A hand-written concrete controller the generator would never produce.
    file_put_contents($controllerOut.'/PetController.php', '<?php // hand-written, edited freely');

    $this->artisan('openapi:check', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--controllers' => true,
    ])->assertExitCode(0);
});

it('exits 2 when --controllers is combined with --no-controllers', function () use ($serverSpec, $tempOut) {
    $this->artisan('openapi:check', [
        '--spec' => $serverSpec(),
        '--output' => $tempOut(),
        '--controllers' => true,
        '--no-controllers' => true,
    ])->assertExitCode(2);
});

it('exits 2 on a configuration error (no spec)', function () use ($tempOut) {
    config()->set('openapi-laravel.spec', '');
    config()->set('openapi-laravel.output.path', $tempOut());

    $this->artisan('openapi:check')->assertExitCode(2);
});

it('exits 2 when the spec cannot be parsed', function () use ($tempOut) {
    config()->set('openapi-laravel.spec', '/no/such/spec.json');
    config()->set('openapi-laravel.output.path', $tempOut());

    $this->artisan('openapi:check')->assertExitCode(2);
});
