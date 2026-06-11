<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\OptionException;
use CodeWithAgents\OpenApiLaravel\Console\StandaloneConfigLoader;

$writeFile = function (string $contents): string {
    $dir = sys_get_temp_dir().'/oal_cfg_loader_'.uniqid();
    mkdir($dir, 0755, true);
    $path = $dir.'/openapi-laravel.json';
    file_put_contents($path, $contents);

    return $path;
};

it('returns an empty config when no file exists and none was requested', function () {
    $config = (new StandaloneConfigLoader)->load(null, sys_get_temp_dir().'/oal_cfg_none_'.uniqid());

    expect($config->spec)->toBeNull()
        ->and($config->outputPath)->toBeNull()
        ->and($config->controllersEnabled)->toBeNull()
        ->and($config->routesEnabled)->toBeNull();
});

it('throws when the explicit --config file is missing', function () {
    (new StandaloneConfigLoader)->load('/no/such/openapi-laravel.json', '/tmp');
})->throws(OptionException::class, 'Config file not found');

it('maps every supported key onto the config object', function () use ($writeFile) {
    $path = $writeFile((string) json_encode([
        'spec' => 'openapi.yaml',
        'output' => ['path' => 'app/Data', 'namespace' => 'App\\Data', 'suffix' => 'Dto', 'prune' => true],
        'controllers' => ['enabled' => false, 'path' => 'app/Http', 'namespace' => 'App\\Http'],
        'routes' => ['enabled' => true, 'path' => 'routes/api.generated.php'],
        'max_depth' => 32,
        'max_bytes' => 1024,
    ]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->spec)->toBe('openapi.yaml')
        ->and($config->outputPath)->toBe('app/Data')
        ->and($config->namespace)->toBe('App\\Data')
        ->and($config->suffix)->toBe('Dto')
        ->and($config->prune)->toBeTrue()
        ->and($config->controllersEnabled)->toBeFalse()
        ->and($config->controllerPath)->toBe('app/Http')
        ->and($config->controllerNamespace)->toBe('App\\Http')
        ->and($config->routesEnabled)->toBeTrue()
        ->and($config->routesPath)->toBe('routes/api.generated.php')
        ->and($config->maxDepth)->toBe(32)
        ->and($config->maxBytes)->toBe(1024);
});

it('discovers the default file name in the given directory', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['spec' => 'discovered.yaml']));

    $config = (new StandaloneConfigLoader)->load(null, dirname($path));

    expect($config->spec)->toBe('discovered.yaml');
});

it('rejects malformed JSON', function () use ($writeFile) {
    $path = $writeFile('{not json');

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, 'Malformed config file');

it('rejects a JSON array document', function () use ($writeFile) {
    $path = $writeFile('["spec"]');

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, 'Malformed config file');

it('rejects an unknown top-level key', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['specc' => 'openapi.yaml']));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Unknown key 'specc'");

it('rejects an unknown nested key', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['controllers' => ['enable' => true]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Unknown key 'controllers.enable'");

it('rejects a scalar where an object section is expected', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => 'routes/api.php']));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'routes'");

it('rejects a non-string spec', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['spec' => 42]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'spec'");

it('rejects a non-boolean controllers.enabled', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['controllers' => ['enabled' => 'yes']]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'controllers.enabled'");

it('rejects a non-integer max_depth', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['max_depth' => '64']));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'max_depth'");
