<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\GenerationPlanner;
use CodeWithAgents\OpenApiLaravel\Console\GenerationRequest;
use CodeWithAgents\OpenApiLaravel\Console\PlanException;
use CodeWithAgents\OpenApiLaravel\Console\PlannedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;

/**
 * Feature tests for subset generation (issue #44) through the real generation
 * pipeline (parse -> closure -> model + server emit). They prove a subset emits
 * ONLY its transitive closure, that the emitted set has no dangling reference
 * (every signature type resolves within the emitted set), that the server
 * scaffold is restricted to the selected operations, and that an unknown
 * tag/schema is a hard error.
 */

/**
 * Write a spec to a temp file and return its path. Exercises a graph with an
 * intentional cross-tag dependency island so subsetting is observable.
 */
function writeSubsetSpec(): string
{
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Subset', 'version' => '1.0.0'],
        'paths' => [
            '/pets' => [
                'get' => [
                    'tags' => ['pets'],
                    'operationId' => 'listPets',
                    'responses' => ['200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet'],
                        ]]],
                    ]],
                ],
                'post' => [
                    'tags' => ['pets'],
                    'operationId' => 'createPet',
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]],
                    'responses' => ['201' => ['description' => 'created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]]],
                ],
            ],
            '/orders' => [
                'get' => [
                    'tags' => ['store'],
                    'operationId' => 'listOrders',
                    'responses' => ['200' => [
                        'description' => 'ok',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Order']]],
                    ]],
                ],
            ],
        ],
        'components' => ['schemas' => [
            'Pet' => [
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'tag' => ['$ref' => '#/components/schemas/Tag'],
                ],
            ],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'Order' => [
                'type' => 'object',
                'properties' => ['item' => ['$ref' => '#/components/schemas/Pet'], 'qty' => ['type' => 'integer']],
            ],
        ]],
    ];

    $path = sys_get_temp_dir().'/oal_subset_'.uniqid().'.json';
    file_put_contents($path, (string) json_encode($spec));

    return $path;
}

/**
 * @param  list<string>  $tags
 * @param  list<string>  $schemas
 */
function planSubset(string $spec, array $tags = [], array $schemas = [], bool $server = false): array
{
    $out = sys_get_temp_dir().'/oal_subset_out_'.uniqid();
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
        onlySchemas: $schemas,
    );

    $plan = (new GenerationPlanner)->plan($request);

    return $plan->files;
}

/**
 * The class basenames (without .php) of the Data files in a planned set.
 *
 * @param  list<PlannedFile>  $files
 * @return list<string>
 */
function dataClassNames(array $files): array
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

it('emits only the selected schema and its closure', function () {
    $files = planSubset(writeSubsetSpec(), schemas: ['Pet']);

    // Pet -> Tag (closure). Order is excluded.
    expect(dataClassNames($files))->toBe(['PetData', 'TagData']);
});

it('excludes schemas outside the closure', function () {
    $files = planSubset(writeSubsetSpec(), schemas: ['Tag']);

    expect(dataClassNames($files))->toBe(['TagData']);
});

it('emits a tag slice plus the operations\' schema closure', function () {
    $files = planSubset(writeSubsetSpec(), tags: ['pets']);

    // The pets operations reference Pet, which closes over Tag. Order is out.
    expect(dataClassNames($files))->toBe(['PetData', 'TagData']);
});

it('unions a tag and a schema selection', function () {
    $files = planSubset(writeSubsetSpec(), tags: ['store'], schemas: ['Tag']);

    // store -> Order -> Pet -> Tag, plus the explicit Tag. Everything here.
    expect(dataClassNames($files))->toBe(['OrderData', 'PetData', 'TagData']);
});

it('has no dangling reference in a subset: every signature type resolves within the emitted set', function () {
    $files = planSubset(writeSubsetSpec(), schemas: ['Pet']);

    $generated = [];
    foreach ($files as $file) {
        $generated[] = new GeneratedFile(basename($file->path, '.php'), $file->content);
    }

    $defined = definedClassNames($generated);
    foreach ($generated as $file) {
        $unresolved = unresolvedSignatureTypes($file->code, $defined);
        expect($unresolved)->toBe([], "Dangling reference(s) in {$file->filename()}: ".implode(', ', $unresolved));
    }
});

it('restricts the server scaffold to the selected operations', function () {
    $files = planSubset(writeSubsetSpec(), tags: ['pets'], server: true);

    $controllers = [];
    $routesContent = '';
    foreach ($files as $file) {
        if ($file->category === PlannedFile::CATEGORY_CONTROLLER) {
            $controllers[] = basename($file->path, '.php');
        }
        if ($file->category === PlannedFile::CATEGORY_ROUTES) {
            $routesContent = $file->content;
        }
    }

    // Only the pets controller is scaffolded; the store controller is not.
    expect($controllers)->toContain('AbstractPetsController')
        ->and($controllers)->not->toContain('AbstractStoreController')
        // The routes file lists the pets operations but not the orders one.
        ->and($routesContent)->toContain('/pets')
        ->and($routesContent)->not->toContain('/orders');
});

it('errors clearly on an unknown schema name', function () {
    expect(fn () => planSubset(writeSubsetSpec(), schemas: ['Ghost']))
        ->toThrow(PlanException::class, 'schema(s) Ghost');
});

it('errors clearly on an unknown tag name', function () {
    expect(fn () => planSubset(writeSubsetSpec(), tags: ['ghosts']))
        ->toThrow(PlanException::class, 'tag(s) ghosts');
});

it('generates the full spec when no subset is selected', function () {
    $files = planSubset(writeSubsetSpec());

    expect(dataClassNames($files))->toBe(['OrderData', 'PetData', 'TagData']);
});

it('threads the --only-schemas flag through the artisan command', function () {
    $spec = writeSubsetSpec();
    $out = sys_get_temp_dir().'/oal_subset_artisan_'.uniqid();

    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.enabled', false);
    config()->set('openapi-laravel.routes.enabled', false);

    $this->artisan('openapi:generate', ['--only-schemas' => 'Pet'])->assertSuccessful();

    $written = glob($out.'/*.php') ?: [];
    $names = array_map(static fn (string $p): string => basename($p, '.php'), $written);
    sort($names);

    expect($names)->toBe(['PetData', 'TagData']);
});

it('fails the artisan command with exit 1 on an unknown subset name', function () {
    $spec = writeSubsetSpec();
    config()->set('openapi-laravel.spec', $spec);
    config()->set('openapi-laravel.output.path', sys_get_temp_dir().'/oal_subset_err_'.uniqid());

    $this->artisan('openapi:generate', ['--only-schemas' => 'Ghost'])
        ->assertExitCode(1);
});
