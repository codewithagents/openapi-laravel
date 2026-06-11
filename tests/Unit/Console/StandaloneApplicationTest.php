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

$writeConfig = function (array $config): string {
    $dir = sys_get_temp_dir().'/oal_standalone_cfg_'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/openapi-laravel.json', json_encode($config, JSON_PRETTY_PRINT));

    return $dir.'/openapi-laravel.json';
};

it('reads spec and output from a --config file', function () use ($spec, $tempOut, $writeConfig) {
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $spec(),
        'output' => ['path' => $out, 'namespace' => 'Acme\\FromConfig'],
    ]);

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
    ]);

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
    ]);

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
    ]);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_dir($out.'/Controllers'))->toBeFalse()
        ->and(is_file($out.'/routes.php'))->toBeFalse();
});

it('lets --controllers and --routes override a config file that disables them', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => false],
    ]);

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
    ]);

    $exit = (new StandaloneApplication)->run(['bin', '--config='.$configPath]);

    expect($exit)->toBe(0)
        ->and(is_file($out.'/http/AbstractPetController.php'))->toBeTrue()
        ->and(file_get_contents($out.'/http/AbstractPetController.php'))->toContain('namespace Acme\Http;')
        ->and(is_file($out.'/api.generated.php'))->toBeTrue();
});

it('honours an asymmetric config: controllers off, routes on', function () use ($tempOut, $writeConfig) {
    $serverSpec = __DIR__.'/../../Fixtures/server/petstore.yaml';
    $out = $tempOut();
    $configPath = $writeConfig([
        'spec' => $serverSpec,
        'output' => ['path' => $out],
        'controllers' => ['enabled' => false],
        'routes' => ['enabled' => true],
    ]);

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
    ]);

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
