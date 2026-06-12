<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\GenerationPlan;
use CodeWithAgents\OpenApiLaravel\Console\GenerationPlanner;
use CodeWithAgents\OpenApiLaravel\Console\GenerationRequest;
use CodeWithAgents\OpenApiLaravel\Console\PlannedFile;

/**
 * Feature tests for path-prefix exclusion (issue #96) through the real
 * generation pipeline. The filter runs BEFORE the subset closure and the
 * operation collector, so an excluded operation produces no controller method
 * and no route, never seeds an --only-tags closure, and generate and check
 * stay in lockstep because both plan from the same filtered document.
 */

/**
 * Write a spec with a swagger-mirror route group (the motivating adopter
 * case) and an internal group, and return its path. The mirror operation
 * references a schema (SwaggerOnly) no other operation uses, so the closure
 * interaction is observable.
 */
function writeExcludeSpec(): string
{
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Exclude', 'version' => '1.0.0'],
        'paths' => [
            '/pets' => [
                'get' => [
                    'tags' => ['pets'],
                    'operationId' => 'listPets',
                    'responses' => ['200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                    ]],
                ],
            ],
            '/internal/metrics' => [
                'get' => [
                    'tags' => ['internal'],
                    'operationId' => 'listMetrics',
                    'responses' => ['200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Metric']]],
                    ]],
                ],
            ],
            '/api/v1/swagger/pets' => [
                'get' => [
                    'tags' => ['pets'],
                    'operationId' => 'listSwaggerPets',
                    'responses' => ['200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SwaggerOnly']]],
                    ]],
                ],
            ],
        ],
        'components' => ['schemas' => [
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'Metric' => ['type' => 'object', 'properties' => ['value' => ['type' => 'number']]],
            'SwaggerOnly' => ['type' => 'object', 'properties' => ['mirror' => ['type' => 'boolean']]],
        ]],
    ];

    $path = sys_get_temp_dir().'/oal_exclude_'.uniqid().'.json';
    file_put_contents($path, (string) json_encode($spec));

    return $path;
}

/**
 * @param  list<string>  $prefixes
 * @param  list<string>  $tags
 */
function planExclude(string $spec, array $prefixes = [], array $tags = [], bool $server = true): GenerationPlan
{
    $out = sys_get_temp_dir().'/oal_exclude_out_'.uniqid();
    $request = new GenerationRequest(
        spec: $spec,
        output: $out,
        namespace: 'App\\Data',
        suffix: 'Data',
        maxDepth: 64,
        maxBytes: null,
        controllers: $server,
        controllerPath: $out.'/Controllers',
        controllerNamespace: 'App\\Http\\Controllers\\Api',
        routes: $server,
        routesPath: $out.'/routes.php',
        enforceClosedObjects: false,
        onlyTags: $tags,
        excludePathPrefixes: $prefixes,
    );

    return (new GenerationPlanner)->plan($request);
}

/**
 * @param  list<PlannedFile>  $files
 * @return list<string>
 */
function excludeDataClassNames(array $files): array
{
    $names = [];
    foreach ($files as $file) {
        if ($file->category === PlannedFile::CATEGORY_DATA) {
            $names[] = basename($file->path, '.php');
        }
    }
    sort($names);

    return $names;
}

/**
 * @param  list<PlannedFile>  $files
 */
function excludeRoutesContent(array $files): string
{
    foreach ($files as $file) {
        if ($file->category === PlannedFile::CATEGORY_ROUTES) {
            return $file->content;
        }
    }

    return '';
}

it('drops excluded operations from controllers and routes', function () {
    $plan = planExclude(writeExcludeSpec(), prefixes: ['/api/v1/swagger']);

    $routes = excludeRoutesContent($plan->files);
    $controllers = '';
    foreach ($plan->files as $file) {
        if ($file->category === PlannedFile::CATEGORY_CONTROLLER) {
            $controllers .= $file->content;
        }
    }

    expect($routes)->toContain('/pets')
        ->and($routes)->toContain('/internal/metrics')
        ->and($routes)->not->toContain('/api/v1/swagger')
        ->and($controllers)->toContain('listPets')
        ->and($controllers)->not->toContain('listSwaggerPets');
});

it('drops a controller entirely when every operation of its tag is excluded', function () {
    $plan = planExclude(writeExcludeSpec(), prefixes: ['/api/v1/swagger', '/internal']);

    $controllers = [];
    foreach ($plan->files as $file) {
        if ($file->category === PlannedFile::CATEGORY_CONTROLLER) {
            $controllers[] = basename($file->path, '.php');
        }
    }

    expect($controllers)->toContain('AbstractPetsController')
        ->and($controllers)->not->toContain('AbstractInternalController')
        ->and(excludeRoutesContent($plan->files))->not->toContain('/internal');
});

it('keeps every component schema by default, even one referenced only by an excluded operation', function () {
    // Without a subset selection the generator emits every component schema,
    // referenced or not; path exclusion alone mirrors that behavior exactly.
    $plan = planExclude(writeExcludeSpec(), prefixes: ['/api/v1/swagger']);

    expect(excludeDataClassNames($plan->files))->toBe(['MetricData', 'PetData', 'SwaggerOnlyData']);
});

it('applies the filter before the --only-tags closure', function () {
    // Without exclusion, the pets tag covers the swagger-mirror operation, so
    // its SwaggerOnly schema lands in the closure.
    $unfiltered = planExclude(writeExcludeSpec(), tags: ['pets']);
    expect(excludeDataClassNames($unfiltered->files))->toBe(['PetData', 'SwaggerOnlyData']);

    // With exclusion, the mirror operation is gone before the closure is
    // resolved, so the schema only it referenced drops out of the slice too.
    $filtered = planExclude(writeExcludeSpec(), prefixes: ['/api/v1/swagger'], tags: ['pets']);
    expect(excludeDataClassNames($filtered->files))->toBe(['PetData']);
});

it('warns on a prefix that matches no path instead of failing', function () {
    $plan = planExclude(writeExcludeSpec(), prefixes: ['/ghost']);

    expect($plan->warnings)->toContain(
        'exclude-path-prefix "/ghost" matched no path in the spec; nothing was excluded for it. Check the prefix against the spec (matching is literal and case-sensitive).',
    )
        // Nothing was excluded, so the full scaffold is still planned.
        ->and(excludeRoutesContent($plan->files))->toContain('/api/v1/swagger');
});

it('produces byte-identical output with no prefixes configured', function () {
    $spec = writeExcludeSpec();

    $baseline = planExclude($spec);
    $explicit = planExclude($spec, prefixes: []);

    $contents = static fn (GenerationPlan $plan): array => array_map(
        static fn (PlannedFile $file): string => basename($file->path).':'.$file->content,
        $plan->files,
    );

    expect($contents($explicit))->toBe($contents($baseline))
        ->and($baseline->warnings)->toBe([]);
});

it('threads repeated --exclude-path-prefix flags through the artisan command', function () {
    $spec = writeExcludeSpec();
    $out = sys_get_temp_dir().'/oal_exclude_artisan_'.uniqid();

    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Controllers');
    config()->set('openapi-laravel.routes.path', $out.'/routes.php');

    $this->artisan('openapi:generate', [
        '--exclude-path-prefix' => ['/api/v1/swagger', '/internal'],
    ])->assertSuccessful();

    $routes = (string) file_get_contents($out.'/routes.php');

    expect($routes)->toContain('/pets')
        ->and($routes)->not->toContain('/api/v1/swagger')
        ->and($routes)->not->toContain('/internal');
});

it('reads the exclude_path_prefixes config key when no flag is passed', function () {
    $spec = writeExcludeSpec();
    $out = sys_get_temp_dir().'/oal_exclude_config_'.uniqid();

    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Controllers');
    config()->set('openapi-laravel.routes.path', $out.'/routes.php');
    config()->set('openapi-laravel.exclude_path_prefixes', ['/internal']);

    $this->artisan('openapi:generate')->assertSuccessful();

    $routes = (string) file_get_contents($out.'/routes.php');

    expect($routes)->toContain('/pets')
        ->and($routes)->toContain('/api/v1/swagger')
        ->and($routes)->not->toContain('/internal');
});

it('lets the flag override the config key (flags beat config)', function () {
    $spec = writeExcludeSpec();
    $out = sys_get_temp_dir().'/oal_exclude_prec_'.uniqid();

    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Controllers');
    config()->set('openapi-laravel.routes.path', $out.'/routes.php');
    config()->set('openapi-laravel.exclude_path_prefixes', ['/pets']);

    $this->artisan('openapi:generate', [
        '--exclude-path-prefix' => ['/internal'],
    ])->assertSuccessful();

    $routes = (string) file_get_contents($out.'/routes.php');

    // The flag replaced the config value: /pets survives, /internal is gone.
    expect($routes)->toContain('/pets')
        ->and($routes)->not->toContain('/internal');
});

it('keeps generate and check in lockstep under the filter', function () {
    $spec = writeExcludeSpec();
    $out = sys_get_temp_dir().'/oal_exclude_lockstep_'.uniqid();

    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Controllers');
    config()->set('openapi-laravel.routes.path', $out.'/routes.php');

    $this->artisan('openapi:generate', [
        '--exclude-path-prefix' => ['/api/v1/swagger'],
    ])->assertSuccessful();

    // Check with the same filter sees the same plan: in sync.
    $this->artisan('openapi:check', [
        '--exclude-path-prefix' => ['/api/v1/swagger'],
    ])->assertExitCode(0);

    // Check WITHOUT the filter plans the unfiltered document, so the written
    // slice registers as drift; the filter demonstrably shapes the plan on
    // both commands identically.
    $this->artisan('openapi:check')->assertExitCode(1);
});
