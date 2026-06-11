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

it('inlines the referenced support classes into <output>/Support and imports them from there (#40)', function () use ($tempOut) {
    // A spec with a date-time field drives exactly one support class. The
    // generator must inline it into the consumer's own Support namespace so the
    // output has no runtime dependency on the generator package.
    $spec = $tempOut().'.json';
    file_put_contents($spec, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Support inline', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Event' => [
                'type' => 'object',
                'properties' => ['at' => ['type' => 'string', 'format' => 'date-time']],
            ],
        ]],
    ]));

    $out = $tempOut();
    config()->set('openapi-laravel.output.namespace', 'App\\Data');

    $this->artisan('openapi:generate', ['--spec' => $spec, '--output' => $out])->assertSuccessful();

    $support = $out.'/Support/Rfc3339DateTimeRule.php';
    expect(is_file($support))->toBeTrue('expected the inlined support class on disk')
        // It lives in the consumer's own Support namespace, self-contained.
        ->and(file_get_contents($support))->toContain('namespace App\Data\Support;')
        ->and(file_get_contents($support))->not->toContain('CodeWithAgents\OpenApiLaravel\Support')
        // and the Data class imports it from there, not from the generator.
        ->and(file_get_contents($out.'/EventData.php'))->toContain('use App\Data\Support\Rfc3339DateTimeRule;')
        ->and(file_get_contents($out.'/EventData.php'))->not->toContain('CodeWithAgents\OpenApiLaravel\Support');
});

it('writes no Support directory for a spec that references no support class (#40)', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $customerSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    // The customer fixture uses no rule/transformer support class, so only the
    // referenced classes are emitted: no empty Support directory is created.
    expect(is_dir($out.'/Support'))->toBeFalse();
});

it('honours the --spec and --output options', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    expect(is_file($out.'/CustomerData.php'))->toBeTrue();
});

it('honours the --namespace option, overriding the configured namespace', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();

    config()->set('openapi-laravel.output.namespace', 'App\\Data');

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
        '--namespace' => 'App\\Dto',
    ])->assertSuccessful();

    expect(is_file($out.'/CustomerData.php'))->toBeTrue()
        ->and(file_get_contents($out.'/CustomerData.php'))->toContain('namespace App\Dto;')
        ->and(file_get_contents($out.'/CustomerData.php'))->not->toContain('namespace App\Data;');
});

it('rejects an illegal --namespace before writing any file', function () use ($customerSpec, $tempOut) {
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $customerSpec(),
        '--output' => $out,
        '--namespace' => 'Not A Namespace',
    ])->assertFailed();

    expect(is_file($out.'/CustomerData.php'))->toBeFalse();
});

it('fails clearly when the spec cannot be read', function () use ($tempOut) {
    config()->set('openapi-laravel.spec', '/no/such/spec.json');
    config()->set('openapi-laravel.output.path', $tempOut());

    $this->artisan('openapi:generate')->assertFailed();
});

it('generates abstract controllers and a routes file by default', function () use ($tempOut) {
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
    ])->assertSuccessful();

    expect(is_file($controllerOut.'/AbstractPetController.php'))->toBeTrue()
        ->and(is_file($routesOut))->toBeTrue()
        ->and(file_get_contents($routesOut))->toContain("Route::get('/pets'")
        // Every route carries a deterministic ->name() from its operationId (#71),
        // and with no middleware/prefix configured the file stays flat (no group).
        ->and(file_get_contents($routesOut))->toContain("->name('listPets');")
        ->and(file_get_contents($routesOut))->not->toContain('->group(');
});

it('wraps the routes in a Route::group when routes.middleware and routes.prefix are configured (#71)', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.path', $out.'/Http/Controllers/Api');
    config()->set('openapi-laravel.routes.path', $routesOut);
    config()->set('openapi-laravel.routes.middleware', ['api', 'throttle:60,1']);
    config()->set('openapi-laravel.routes.prefix', 'api/v1');

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
    ])->assertSuccessful();

    $routes = file_get_contents($routesOut);
    expect($routes)->toContain("Route::middleware(['api', 'throttle:60,1'])->prefix('api/v1')->group(function (): void {")
        ->and($routes)->toContain("    Route::get('/pets', [PetController::class, 'listPets'])->name('listPets');");
});

it('skips controllers and routes with --no-controllers and --no-routes', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--no-controllers' => true,
        '--no-routes' => true,
    ])->assertSuccessful();

    expect(is_dir($controllerOut))->toBeFalse()
        ->and(is_file($routesOut))->toBeFalse()
        ->and(is_file($out.'/PetData.php'))->toBeTrue();
});

it('lets the config disable the scaffold when no flag is passed', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.enabled', false);
    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.enabled', false);
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
    ])->assertSuccessful();

    expect(is_dir($controllerOut))->toBeFalse()
        ->and(is_file($routesOut))->toBeFalse();
});

it('lets --controllers and --routes override a config that disables them', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.enabled', false);
    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.enabled', false);
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--controllers' => true,
        '--routes' => true,
    ])->assertSuccessful();

    expect(is_file($controllerOut.'/AbstractPetController.php'))->toBeTrue()
        ->and(is_file($routesOut))->toBeTrue();
});

it('lets --no-controllers and --no-routes override a config that enables them', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';

    config()->set('openapi-laravel.controllers.enabled', true);
    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.enabled', true);
    config()->set('openapi-laravel.routes.path', $routesOut);

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--no-controllers' => true,
        '--no-routes' => true,
    ])->assertSuccessful();

    expect(is_dir($controllerOut))->toBeFalse()
        ->and(is_file($routesOut))->toBeFalse();
});

it('rejects --controllers combined with --no-controllers with exit 2 and writes nothing', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--controllers' => true,
        '--no-controllers' => true,
    ])->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('rejects --routes combined with --no-routes with exit 2 and writes nothing', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--routes' => true,
        '--no-routes' => true,
    ])->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('rejects --enforce-closed-objects combined with --no-enforce-closed-objects with exit 2 and writes nothing', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $out,
        '--enforce-closed-objects' => true,
        '--no-enforce-closed-objects' => true,
    ])->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('enforces additionalProperties:false by default and drops the rule under --no-enforce-closed-objects (#30)', function () use ($tempOut) {
    $spec = $tempOut().'.json';
    file_put_contents($spec, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Closed object', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Closed' => [
                'type' => 'object',
                'required' => ['known'],
                'additionalProperties' => false,
                'properties' => ['known' => ['type' => 'string']],
            ],
        ]],
    ]));

    // Default run: the closed-object rule and its inlined support class appear.
    $strictOut = $tempOut();
    $this->artisan('openapi:generate', ['--spec' => $spec, '--output' => $strictOut])->assertSuccessful();
    expect(file_get_contents($strictOut.'/ClosedData.php'))->toContain('new NoUnknownPropertiesRule(')
        ->and(is_file($strictOut.'/Support/NoUnknownPropertiesRule.php'))->toBeTrue();

    // Opt-out run: no rule and no inlined support class.
    $lenientOut = $tempOut();
    $this->artisan('openapi:generate', ['--spec' => $spec, '--output' => $lenientOut, '--no-enforce-closed-objects' => true])->assertSuccessful();
    expect(file_get_contents($lenientOut.'/ClosedData.php'))->not->toContain('NoUnknownPropertiesRule')
        ->and(is_file($lenientOut.'/Support/NoUnknownPropertiesRule.php'))->toBeFalse();
});

it('fails when --controllers is requested but the controllers path is unset', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';

    config()->set('openapi-laravel.controllers.path', '');

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $tempOut(),
        '--controllers' => true,
    ])->assertFailed();
});

it('fails when --routes is requested but the routes path is unset', function () use ($tempOut) {
    $spec = __DIR__.'/../../Fixtures/server/petstore.yaml';

    config()->set('openapi-laravel.routes.path', '');

    $this->artisan('openapi:generate', [
        '--spec' => $spec,
        '--output' => $tempOut(),
        '--routes' => true,
    ])->assertFailed();
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
