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
        'routes' => ['enabled' => true, 'path' => 'routes/api.generated.php', 'middleware' => ['api', 'throttle:60,1'], 'prefix' => 'api/v1'],
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
        ->and($config->routesMiddleware)->toBe(['api', 'throttle:60,1'])
        ->and($config->routesPrefix)->toBe('api/v1')
        ->and($config->maxDepth)->toBe(32)
        ->and($config->maxBytes)->toBe(1024);
});

it('defaults routes.middleware and routes.prefix to null when absent (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['enabled' => true]]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->routesMiddleware)->toBeNull()
        ->and($config->routesPrefix)->toBeNull();
});

it('reads controllers.laravel_conventions as a nullable boolean (#94)', function () use ($writeFile) {
    $unset = (new StandaloneConfigLoader)->load($writeFile((string) json_encode(['controllers' => ['enabled' => true]])), '/tmp');
    $on = (new StandaloneConfigLoader)->load($writeFile((string) json_encode(['controllers' => ['laravel_conventions' => true]])), '/tmp');
    $off = (new StandaloneConfigLoader)->load($writeFile((string) json_encode(['controllers' => ['laravel_conventions' => false]])), '/tmp');

    expect($unset->laravelConventions)->toBeNull()
        ->and($on->laravelConventions)->toBeTrue()
        ->and($off->laravelConventions)->toBeFalse();
});

it('rejects a non-boolean controllers.laravel_conventions (#94)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['controllers' => ['laravel_conventions' => 'yes']]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'controllers.laravel_conventions'");

it('keeps a parameterized middleware name intact, never comma-splitting it (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['middleware' => ['throttle:60,1']]]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->routesMiddleware)->toBe(['throttle:60,1']);
});

it('rejects a comma-separated string for routes.middleware (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['middleware' => 'api,auth']]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'routes.middleware'");

it('rejects a non-string entry inside routes.middleware (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['middleware' => ['api', 42]]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, 'every entry must be a string');

it('rejects an object value for routes.middleware (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['middleware' => ['a' => 'b']]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'routes.middleware'");

it('rejects a non-string routes.prefix (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['prefix' => 42]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'routes.prefix'");

it('drops empty and whitespace-only middleware entries (#71)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['middleware' => ['  api  ', '', '   ']]]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->routesMiddleware)->toBe(['api']);
});

it('reads security.middleware_map with string and list values, normalized to lists (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['security' => ['middleware_map' => [
        'bearerAuth' => 'auth:sanctum',
        'apiKey' => ['auth.apikey', 'throttle:60,1'],
        'handledElsewhere' => [],
    ]]]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->securityMiddlewareMap)->toBe([
        'bearerAuth' => ['auth:sanctum'],
        'apiKey' => ['auth.apikey', 'throttle:60,1'],
        'handledElsewhere' => [],
    ]);
});

it('defaults security.middleware_map to null when absent (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['routes' => ['enabled' => true]]));

    expect((new StandaloneConfigLoader)->load($path, '/tmp')->securityMiddlewareMap)->toBeNull();
});

it('rejects a non-object security.middleware_map (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['security' => ['middleware_map' => ['auth:sanctum']]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'security.middleware_map'");

it('rejects a non-string, non-list value inside security.middleware_map (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['security' => ['middleware_map' => ['bearerAuth' => 42]]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'security.middleware_map.bearerAuth'");

it('rejects a non-string entry inside a security.middleware_map list value (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['security' => ['middleware_map' => ['bearerAuth' => ['auth', 42]]]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, 'every entry must be a string');

it('rejects an unknown key inside the security section (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['security' => ['middleware' => []]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Unknown key 'security.middleware'");

it('keeps a parameterized middleware name in the map intact, never comma-splitting it (#77)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['security' => ['middleware_map' => ['bearerAuth' => 'throttle:60,1']]]));

    expect((new StandaloneConfigLoader)->load($path, '/tmp')->securityMiddlewareMap)->toBe(['bearerAuth' => ['throttle:60,1']]);
});

it('reads only_tags and only_schemas as a comma-separated string', function () use ($writeFile) {
    $path = $writeFile((string) json_encode([
        'only_tags' => 'pets, store',
        'only_schemas' => 'Pet,Tag',
    ]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->onlyTags)->toBe(['pets', 'store'])
        ->and($config->onlySchemas)->toBe(['Pet', 'Tag']);
});

it('reads only_tags and only_schemas as a JSON list', function () use ($writeFile) {
    $path = $writeFile((string) json_encode([
        'only_tags' => ['pets', 'store'],
        'only_schemas' => ['Pet'],
    ]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->onlyTags)->toBe(['pets', 'store'])
        ->and($config->onlySchemas)->toBe(['Pet']);
});

it('defaults only_tags and only_schemas to null when absent', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['spec' => 'openapi.yaml']));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->onlyTags)->toBeNull()
        ->and($config->onlySchemas)->toBeNull();
});

it('rejects a non-string element inside only_schemas', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['only_schemas' => ['Pet', 42]]));

    expect(fn () => (new StandaloneConfigLoader)->load($path, '/tmp'))
        ->toThrow(OptionException::class, 'every entry must be a string');
});

it('rejects an object value for only_tags', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['only_tags' => ['a' => 'b']]));

    expect(fn () => (new StandaloneConfigLoader)->load($path, '/tmp'))
        ->toThrow(OptionException::class, 'comma-separated string or a list of strings');
});

it('reads exclude_path_prefixes as a JSON list (#96)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode([
        'exclude_path_prefixes' => ['/api/v1/swagger', '/internal'],
    ]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->excludePathPrefixes)->toBe(['/api/v1/swagger', '/internal']);
});

it('defaults exclude_path_prefixes to null when absent (#96)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['spec' => 'openapi.yaml']));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->excludePathPrefixes)->toBeNull();
});

it('trims exclude_path_prefixes entries and drops empty ones (#96)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode([
        'exclude_path_prefixes' => ['  /internal  ', '', '   '],
    ]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->excludePathPrefixes)->toBe(['/internal']);
});

it('rejects a comma-separated string for exclude_path_prefixes (#96)', function () use ($writeFile) {
    // Never comma-split: a literal URL path may contain a comma, so only the
    // explicit list form is accepted.
    $path = $writeFile((string) json_encode(['exclude_path_prefixes' => '/api/v1/swagger,/internal']));

    expect(fn () => (new StandaloneConfigLoader)->load($path, '/tmp'))
        ->toThrow(OptionException::class, "Invalid 'exclude_path_prefixes'");
});

it('rejects a non-string entry inside exclude_path_prefixes (#96)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['exclude_path_prefixes' => ['/internal', 42]]));

    expect(fn () => (new StandaloneConfigLoader)->load($path, '/tmp'))
        ->toThrow(OptionException::class, 'every entry must be a string');
});

it('rejects an object value for exclude_path_prefixes (#96)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['exclude_path_prefixes' => ['a' => '/internal']]));

    expect(fn () => (new StandaloneConfigLoader)->load($path, '/tmp'))
        ->toThrow(OptionException::class, "Invalid 'exclude_path_prefixes'");
});

it('discovers the default file name in the given directory', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['spec' => 'discovered.yaml']));

    $config = (new StandaloneConfigLoader)->load(null, dirname($path));

    expect($config->spec)->toBe('discovered.yaml');
});

it('rejects a config file larger than 1 MiB before reading it', function () use ($writeFile) {
    // One byte over the limit; the content is valid JSON so only the size
    // guard can be the reason for the rejection.
    $path = $writeFile('{"spec": "'.str_repeat('a', 1_048_576 - 11).'"}');

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, 'exceeds the 1048576 byte limit');

it('accepts a config file exactly at the 1 MiB limit', function () use ($writeFile) {
    $value = str_repeat('a', 1_048_576 - 12);
    $path = $writeFile('{"spec": "'.$value.'"}');

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->spec)->toBe($value);
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

it('maps controllers.base_class and output.validation_trait onto the config (#83)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode([
        'output' => ['validation_trait' => 'App\\Support\\ApiMessages'],
        'controllers' => ['base_class' => 'App\\Http\\Controllers\\Controller'],
    ]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->validationTrait)->toBe('App\\Support\\ApiMessages')
        ->and($config->controllerBaseClass)->toBe('App\\Http\\Controllers\\Controller');
});

it('defaults controllers.base_class and output.validation_trait to null when absent (#83)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['controllers' => ['enabled' => true]]));

    $config = (new StandaloneConfigLoader)->load($path, '/tmp');

    expect($config->controllerBaseClass)->toBeNull()
        ->and($config->validationTrait)->toBeNull();
});

it('rejects an empty controllers.base_class (#83)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['controllers' => ['base_class' => '']]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'controllers.base_class'");

it('rejects a whitespace-only output.validation_trait (#83)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['output' => ['validation_trait' => '   ']]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'output.validation_trait'");

it('rejects a non-string controllers.base_class (#83)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['controllers' => ['base_class' => 42]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'controllers.base_class'");

it('rejects a non-string output.validation_trait (#83)', function () use ($writeFile) {
    $path = $writeFile((string) json_encode(['output' => ['validation_trait' => ['a']]]));

    (new StandaloneConfigLoader)->load($path, '/tmp');
})->throws(OptionException::class, "Invalid 'output.validation_trait'");
