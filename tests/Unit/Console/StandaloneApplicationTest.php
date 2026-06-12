<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\StandaloneApplication;

$spec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_standalone_'.uniqid();

it('generates classes from --spec and --output', function () use ($spec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$spec(), '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/CustomerData.php'))->toBeTrue()
        ->and(is_file($out.'/CustomerStatus.php'))->toBeTrue();
});

it('honours a custom --namespace', function () use ($spec, $tempOut) {
    $out = $tempOut();

    (new StandaloneApplication)->run(['bin', '--spec='.$spec(), '--output='.$out, '--namespace=Acme\\Dto']);

    expect(file_get_contents($out.'/CustomerData.php'))->toContain('namespace Acme\Dto;');
});

it('exits non-zero when required arguments are missing', function () {
    expect((new StandaloneApplication)->run(['bin']))->toBe(1);
});

it('exits non-zero when the spec cannot be read', function () use ($tempOut) {
    $exit = (new StandaloneApplication)->run(['bin', '--spec=/no/such.json', '--output='.$tempOut()]);

    expect($exit)->toBe(1);
});

// C-3: operator-supplied identifiers are validated before any file is written.
// An invalid option is a configuration error, so it exits 2 on every surface.

it('rejects an illegal --namespace and writes nothing', function () use ($spec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$spec(), '--output='.$out, "--namespace=App\\Data'); system('x'); //"]);

    expect($exit)->toBe(2)
        ->and(is_file($out.'/CustomerData.php'))->toBeFalse();
});

it('rejects an illegal --suffix and writes nothing', function () use ($spec, $tempOut) {
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$spec(), '--output='.$out, '--suffix=Da ta']);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('rejects an illegal --controller-namespace and writes nothing', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$out.'/data',
        '--controllers',
        '--controller-output='.$out.'/controllers',
        '--controller-namespace=Bad Namespace',
    ]);

    expect($exit)->toBe(2)
        ->and(is_dir($out.'/controllers'))->toBeFalse();
});

it('generates controllers and routes from the server flags', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $controllerOut = $out.'/controllers';
    $routesOut = $out.'/routes/api.generated.php';

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$out.'/data',
        '--controllers',
        '--controller-output='.$controllerOut,
        '--controller-namespace=Acme\\Http\\Controllers',
        '--routes',
        '--routes-output='.$routesOut,
    ]);

    expect($exit)->toBe(0)
        ->and(is_file($controllerOut.'/AbstractPetController.php'))->toBeTrue()
        ->and(file_get_contents($controllerOut.'/AbstractPetController.php'))->toContain('namespace Acme\Http\Controllers;')
        ->and(is_file($routesOut))->toBeTrue()
        ->and(file_get_contents($routesOut))->toContain('use Acme\Http\Controllers\PetController;');
});

it('generates controllers and routes by default into derived paths', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec, '--output='.$out]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_file($out.'/Controllers/AbstractPetController.php'))->toBeTrue()
        ->and(is_file($out.'/routes.php'))->toBeTrue();
});

it('skips the scaffold with --no-controllers and --no-routes', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$out,
        '--no-controllers',
        '--no-routes',
    ]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_dir($out.'/Controllers'))->toBeFalse()
        ->and(is_file($out.'/routes.php'))->toBeFalse();
});

it('exits 2 when --controllers is combined with --no-controllers', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$out,
        '--controllers',
        '--no-controllers',
    ]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('exits 2 when --routes is combined with --no-routes', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$tempOut(),
        '--routes',
        '--no-routes',
    ]);

    expect($exit)->toBe(2);
});

it('exits 2 when --enforce-closed-objects is combined with --no-enforce-closed-objects', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run([
        'bin',
        '--spec='.$serverSpec,
        '--output='.$out,
        '--enforce-closed-objects',
        '--no-enforce-closed-objects',
    ]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('exits 2 when check is run with conflicting scaffold flags', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';

    $exit = (new StandaloneApplication)->run([
        'bin',
        'check',
        '--spec='.$serverSpec,
        '--output='.$tempOut(),
        '--controllers',
        '--no-controllers',
    ]);

    expect($exit)->toBe(2);
});

// Config file: openapi-laravel.json mirrors config/openapi-laravel.php, flags win.
//
// Config-sourced write paths are contained to the directory the config lives in
// (issue #54), so a config that points its output at a directory inside that
// root is the realistic, accepted shape. $writeConfig drops the file at the root
// of the given output directory so the absolute output path stays contained;
// escaping paths get their own dedicated containment tests.
$writeConfig = function (array $config, ?string $root = null): string {
    $dir = $root ?? sys_get_temp_dir().'/oal_standalone_cfg_'.uniqid();
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir.'/openapi-laravel.json', json_encode($config, JSON_PRETTY_PRINT));

    return $dir.'/openapi-laravel.json';
};

it('reads spec and output from a --config file', function () use ($spec, $tempOut, $writeConfig) {
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $spec(),
        'output' => ['path' => $out, 'namespace' => 'Acme\\FromConfig'],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/CustomerData.php'))->toBeTrue()
        ->and(file_get_contents($out.'/CustomerData.php'))->toContain('namespace Acme\FromConfig;');
});

it('discovers openapi-laravel.json in the working directory', function () use ($spec, $tempOut, $writeConfig) {
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $spec(),
        'output' => ['path' => $out],
    ], $out);

    $previous = getcwd();
    chdir(dirname($configPath));

    try {
        $exit = (new StandaloneApplication)->run(['bin']);
    } finally {
        chdir((string) $previous);
    }

    expect($exit)->toBe(0)
        ->and(is_file($out.'/CustomerData.php'))->toBeTrue();
});

it('lets flags override config file values', function () use ($spec, $tempOut, $writeConfig) {
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $spec(),
        'output' => ['path' => $out, 'namespace' => 'Acme\\FromConfig'],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath, '--namespace=Acme\\FromFlag']);

    expect($exit)->toBe(0)
        ->and(file_get_contents($out.'/CustomerData.php'))->toContain('namespace Acme\FromFlag;');
});

it('honours controllers.enabled=false from the config file', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => false],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_dir($out.'/Controllers'))->toBeFalse()
        ->and(is_file($out.'/routes.php'))->toBeFalse();
});

it('enforces additionalProperties:false by default, honours enforce_closed_objects:false from config, and lets --no-enforce-closed-objects override (#30)', function () use ($tempOut, $writeConfig) {
    $closedSpec = $tempOut().'.json';
    file_put_contents($closedSpec, json_encode([
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

    // Default (no config key, no flag): enforcement is on, the rule appears.
    $strictOut = $tempOut();
    (new StandaloneApplication)->run(['bin', '--spec='.$closedSpec, '--output='.$strictOut, '--no-controllers', '--no-routes']);
    expect(file_get_contents($strictOut.'/ClosedData.php'))->toContain('new NoUnknownPropertiesRule(')
        ->and(is_file($strictOut.'/Support/NoUnknownPropertiesRule.php'))->toBeTrue();

    // Config key enforce_closed_objects: false opts out: no rule, no support class.
    $configOut = $tempOut();
    $configPath = $writeConfig([
        'spec' => $closedSpec,
        'output' => ['path' => $configOut],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => false],
        'enforce_closed_objects' => false,
    ], $configOut);
    (new StandaloneApplication)->run(['bin', '--config='.$configPath]);
    expect(file_get_contents($configOut.'/ClosedData.php'))->not->toContain('NoUnknownPropertiesRule')
        ->and(is_file($configOut.'/Support/NoUnknownPropertiesRule.php'))->toBeFalse();

    // The flag beats a config key that enables: --no-enforce-closed-objects wins.
    $flagOut = $tempOut();
    $flagConfigPath = $writeConfig([
        'spec' => $closedSpec,
        'output' => ['path' => $flagOut],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => false],
        'enforce_closed_objects' => true,
    ], $flagOut);
    (new StandaloneApplication)->run(['bin', '--config='.$flagConfigPath, '--no-enforce-closed-objects']);
    expect(file_get_contents($flagOut.'/ClosedData.php'))->not->toContain('NoUnknownPropertiesRule');
});

it('lets --controllers and --routes override a config file that disables them', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => false],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath, '--controllers', '--routes']);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/Controllers/AbstractPetController.php'))->toBeTrue()
        ->and(is_file($out.'/routes.php'))->toBeTrue();
});

it('honours the scaffold paths from the config file', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out.'/data'],
        'controllers' => ['path' => $out.'/http', 'namespace' => 'Acme\\Http'],
        'routes' => ['path' => $out.'/api.generated.php'],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/http/AbstractPetController.php'))->toBeTrue()
        ->and(file_get_contents($out.'/http/AbstractPetController.php'))->toContain('namespace Acme\Http;')
        ->and(is_file($out.'/api.generated.php'))->toBeTrue();
});

it('wraps the routes per routes.middleware and routes.prefix from the config file (#71)', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'routes' => ['middleware' => ['api', 'throttle:60,1'], 'prefix' => 'api/v1'],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    $routes = file_get_contents($out.'/routes.php');
    expect($exit)->toBe(0)
        ->and($routes)->toContain("Route::middleware(['api', 'throttle:60,1'])->prefix('api/v1')->group(function (): void {")
        ->and($routes)->toContain("    Route::get('/pets', [PetController::class, 'listPets'])->name('listPets');");
});

it('maps security schemes to route middleware per security.middleware_map from the config file (#77)', function () use ($tempOut, $writeConfig) {
    $securedSpec = __DIR__.'/../../Fixtures/server/secured-petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $securedSpec,
        'output' => ['path' => $out],
        'security' => ['middleware_map' => ['bearerAuth' => 'auth:sanctum', 'apiKey' => ['auth.apikey']]],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    $routes = file_get_contents($out.'/routes.php');
    expect($exit)->toBe(0)
        ->and($routes)->toContain("Route::get('/pets', [PetController::class, 'listPets'])->name('listPets')->middleware(['auth:sanctum']);")
        ->and($routes)->toContain("Route::post('/pets', [PetController::class, 'createPet'])->name('createPet')->middleware(['auth.apikey', RespondsWithStatus::class.':201']);")
        // security: [] keeps the health probe public.
        ->and($routes)->toContain("Route::get('/health', [MetaController::class, 'getHealth'])->name('getHealth');");
});

it('keeps the routes flat with names when neither routes.middleware nor routes.prefix is set (#71)', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    $routes = file_get_contents($out.'/routes.php');
    expect($exit)->toBe(0)
        ->and($routes)->toContain("Route::get('/pets', [PetController::class, 'listPets'])->name('listPets');")
        ->and($routes)->not->toContain('->group(');
});

it('honours an asymmetric config: controllers off, routes on', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => true],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_dir($out.'/Controllers'))->toBeFalse()
        ->and(is_file($out.'/routes.php'))->toBeTrue();
});

it('honours an asymmetric config: controllers on, routes off', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'controllers' => ['enabled' => true],
        'routes' => ['enabled' => false],
    ], $out);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/Controllers/AbstractPetController.php'))->toBeTrue()
        ->and(is_file($out.'/routes.php'))->toBeFalse();
});

it('rejects malformed JSON in the config file and writes nothing', function () use ($tempOut) {
    $dir = sys_get_temp_dir().'/oal_standalone_cfg_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/openapi-laravel.json', '{not json');
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$dir.'/openapi-laravel.json', '--spec=ignored', '--output='.$out]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('rejects an oversized config file and writes nothing', function () use ($tempOut) {
    $dir = sys_get_temp_dir().'/oal_standalone_cfg_'.uniqid();
    mkdir($dir, 0755, true);
    // Just over the 1 MiB limit; the padding lives inside a valid JSON string
    // so only the size guard can be the reason for the rejection.
    file_put_contents($dir.'/openapi-laravel.json', '{"spec": "'.str_repeat('a', 1_048_576).'"}');
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$dir.'/openapi-laravel.json', '--output='.$out]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('exits 2 when the --config file does not exist', function () use ($spec, $tempOut) {
    $exit = (new StandaloneApplication)->run([
        'bin',
        '--config=/no/such/openapi-laravel.json',
        '--spec='.$spec(),
        '--output='.$tempOut(),
    ]);

    expect($exit)->toBe(2);
});

it('rejects an unknown key in the config file and writes nothing', function () use ($spec, $tempOut, $writeConfig) {
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $spec(),
        'output' => ['path' => $out],
        'controlers' => ['enabled' => true],
    ]);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('validates a namespace from the config file like a flag', function () use ($spec, $tempOut, $writeConfig) {
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $spec(),
        'output' => ['path' => $out, 'namespace' => 'Not A Namespace'],
    ]);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(2)
        ->and(is_dir($out))->toBeFalse();
});

it('restricts the standalone scaffold to a tag with --only-tags', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec, '--output='.$out, '--only-tags=pet']);

    // The pet tag covers every pet operation, whose only schema is Pet.
    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_file($out.'/Controllers/AbstractPetController.php'))->toBeTrue();
});

it('generates only the named schema with --only-schemas', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run([
        'bin', '--spec='.$serverSpec, '--output='.$out, '--only-schemas=Pet', '--no-controllers', '--no-routes',
    ]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue();
});

it('exits non-zero on an unknown --only-schemas name', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run(['bin', '--spec='.$serverSpec, '--output='.$out, '--only-schemas=Ghost']);

    expect($exit)->toBe(1)
        ->and(is_dir($out))->toBeFalse();
});

it('collects every occurrence of the repeatable --exclude-path-prefix flag (#96)', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $exit = (new StandaloneApplication)->run([
        'bin', '--spec='.$serverSpec, '--output='.$out,
        '--exclude-path-prefix=/health', '--exclude-path-prefix=/pets/{petId}',
    ]);

    $routes = (string) file_get_contents($out.'/routes.php');

    // Both prefixes apply (the general option parser is last-wins, so this
    // proves the repeatable flag keeps every occurrence).
    expect($exit)->toBe(0)
        ->and($routes)->toContain("'/pets'")
        ->and($routes)->not->toContain('/health')
        ->and($routes)->not->toContain('{petId}');
});

it('reads exclude_path_prefixes from the config file when no flag is passed (#96)', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configDir = sys_get_temp_dir().'/oal_standalone_cfg_'.uniqid();
    mkdir($configDir, 0755, true);
    $configPath = $configDir.'/openapi-laravel.json';
    file_put_contents($configPath, (string) json_encode(['exclude_path_prefixes' => ['/health']]));

    $exit = (new StandaloneApplication)->run([
        'bin', '--config='.$configPath, '--spec='.$serverSpec, '--output='.$out,
    ]);

    $routes = (string) file_get_contents($out.'/routes.php');

    expect($exit)->toBe(0)
        ->and($routes)->toContain("'/pets'")
        ->and($routes)->not->toContain('/health');
});

it('lets the --exclude-path-prefix flag override the config key (#96)', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configDir = sys_get_temp_dir().'/oal_standalone_cfg_'.uniqid();
    mkdir($configDir, 0755, true);
    $configPath = $configDir.'/openapi-laravel.json';
    file_put_contents($configPath, (string) json_encode(['exclude_path_prefixes' => ['/pets']]));

    $exit = (new StandaloneApplication)->run([
        'bin', '--config='.$configPath, '--spec='.$serverSpec, '--output='.$out,
        '--exclude-path-prefix=/health',
    ]);

    $routes = (string) file_get_contents($out.'/routes.php');

    // The flag replaced the config value: /pets survives, /health is gone.
    expect($exit)->toBe(0)
        ->and($routes)->toContain("'/pets'")
        ->and($routes)->not->toContain('/health');
});

it('keeps standalone generate and check in lockstep under --exclude-path-prefix (#96)', function () use ($tempOut) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();

    $generate = (new StandaloneApplication)->run([
        'bin', '--spec='.$serverSpec, '--output='.$out, '--exclude-path-prefix=/health',
    ]);
    expect($generate)->toBe(0);

    // Check with the same filter plans the same filtered file set: in sync.
    $inSync = (new StandaloneApplication)->run([
        'bin', 'check', '--spec='.$serverSpec, '--output='.$out, '--exclude-path-prefix=/health',
    ]);
    expect($inSync)->toBe(0);

    // Check without the filter plans the unfiltered document, so the written
    // slice registers as drift: the filter shapes both subcommands identically.
    $drift = (new StandaloneApplication)->run([
        'bin', 'check', '--spec='.$serverSpec, '--output='.$out,
    ]);
    expect($drift)->toBe(1);
});
