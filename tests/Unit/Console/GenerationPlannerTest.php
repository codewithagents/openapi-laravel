<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\DriftChecker;
use CodeWithAgents\OpenApiLaravel\Console\DriftStatus;
use CodeWithAgents\OpenApiLaravel\Console\GenerationPlanner;
use CodeWithAgents\OpenApiLaravel\Console\GenerationRequest;
use CodeWithAgents\OpenApiLaravel\Console\PlanException;
use CodeWithAgents\OpenApiLaravel\Console\PlannedFile;
use CodeWithAgents\OpenApiLaravel\Console\PlanWriter;

$customerSpec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$querySpec = fn (): string => __DIR__.'/../../Fixtures/server/query-parameters.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_planner_'.uniqid();

$request = fn (string $spec, string $output, bool $server = false, ?string $controllerPath = null, ?string $routesPath = null): GenerationRequest => new GenerationRequest(
    $spec,
    $output,
    'App\\Data',
    'Data',
    64,
    null,
    $server,
    $controllerPath,
    'App\\Http\\Controllers\\Api',
    $server,
    $routesPath,
);

it('plans Data files at the same paths the writer would write', function () use ($customerSpec, $tempOut, $request) {
    $out = $tempOut();
    $plan = (new GenerationPlanner)->plan($request($customerSpec(), $out));

    $paths = array_map(static fn (PlannedFile $f): string => $f->path, $plan->files);

    expect($paths)->toContain($out.'/CustomerData.php')
        ->and($plan->noModelSchemas)->toBeFalse();
});

it('writes a plan that the checker then sees as in sync (determinism)', function () use ($customerSpec, $tempOut, $request) {
    $out = $tempOut();
    $planner = new GenerationPlanner;

    $plan = $planner->plan($request($customerSpec(), $out));
    (new PlanWriter)->write($plan, PlannedFile::CATEGORY_DATA);

    // Planning again and reading from disk must match byte-for-byte.
    $replan = $planner->plan($request($customerSpec(), $out));
    foreach ($replan->files as $file) {
        expect(file_get_contents($file->path))->toBe($file->content);
    }
});

it('includes controllers and the routes file when server targets are requested', function () use ($serverSpec, $tempOut, $request) {
    $out = $tempOut();
    $controllerPath = $out.'/Http';
    $routesPath = $out.'/routes/api.generated.php';

    $plan = (new GenerationPlanner)->plan($request($serverSpec(), $out, true, $controllerPath, $routesPath));

    $controllerPaths = array_map(static fn (PlannedFile $f): string => $f->path, $plan->filesByCategory(PlannedFile::CATEGORY_CONTROLLER));
    $routePaths = array_map(static fn (PlannedFile $f): string => $f->path, $plan->filesByCategory(PlannedFile::CATEGORY_ROUTES));

    expect($controllerPaths)->toContain($controllerPath.'/AbstractPetController.php')
        ->and($routePaths)->toBe([$routesPath]);
});

it('plans query Data classes even when controllers and routes are both disabled (model-only run)', function () use ($querySpec, $tempOut, $request) {
    $out = $tempOut();
    $plan = (new GenerationPlanner)->plan($request($querySpec(), $out));

    // Query classes are data-layer output: a model-only run must still emit
    // them, in the CATEGORY_DATA bucket, while planning no scaffold files.
    $dataPaths = array_map(static fn (PlannedFile $f): string => $f->path, $plan->filesByCategory(PlannedFile::CATEGORY_DATA));

    expect($dataPaths)->toContain($out.'/Widget/ListWidgetsQueryData.php')
        ->and($dataPaths)->toContain($out.'/Widget/CreateWidgetQueryData.php')
        ->and($dataPaths)->toContain($out.'/Widget/UploadBlobQueryData.php')
        ->and($plan->filesByCategory(PlannedFile::CATEGORY_CONTROLLER))->toBe([])
        ->and($plan->filesByCategory(PlannedFile::CATEGORY_ROUTES))->toBe([]);
});

it('plans component ResponseData classes even when controllers and routes are both disabled (#116)', function () use ($tempOut, $request) {
    $out = $tempOut();
    $spec = __DIR__.'/../../Fixtures/server/component-responses.yaml';
    $plan = (new GenerationPlanner)->plan($request($spec, $out));

    // The shared component-response classes are data-layer output exactly
    // like the query and inline-body classes: a --no-controllers/--no-routes
    // run must still emit them, in the CATEGORY_DATA bucket (here in the
    // single Widget tag group its referencing operation owns), while
    // planning no scaffold files.
    $dataPaths = array_map(static fn (PlannedFile $f): string => $f->path, $plan->filesByCategory(PlannedFile::CATEGORY_DATA));

    expect($dataPaths)->toContain($out.'/Widget/WidgetPageResponseData.php')
        ->and($dataPaths)->toContain($out.'/WidgetData.php')
        ->and($plan->filesByCategory(PlannedFile::CATEGORY_CONTROLLER))->toBe([])
        ->and($plan->filesByCategory(PlannedFile::CATEGORY_ROUTES))->toBe([]);
});

it('plans byte-identical Data files with and without the server scaffold (lockstep)', function () use ($querySpec, $tempOut, $request) {
    $out = $tempOut();
    $planner = new GenerationPlanner;

    $contentByPath = static function (array $files): array {
        $map = [];
        foreach ($files as $file) {
            $map[$file->path] = $file->content;
        }
        ksort($map);

        return $map;
    };

    $modelOnly = $planner->plan($request($querySpec(), $out));
    $full = $planner->plan($request($querySpec(), $out, true, $out.'/Http', $out.'/routes/api.generated.php'));

    expect($contentByPath($modelOnly->filesByCategory(PlannedFile::CATEGORY_DATA)))
        ->toBe($contentByPath($full->filesByCategory(PlannedFile::CATEGORY_DATA)));
});

it('keeps a written model-only plan in sync with the drift check, query classes included', function () use ($querySpec, $tempOut, $request) {
    $out = $tempOut();
    $planner = new GenerationPlanner;

    $plan = $planner->plan($request($querySpec(), $out));
    $writer = new PlanWriter;
    $writer->write($plan, PlannedFile::CATEGORY_DATA);
    $writer->write($plan, PlannedFile::CATEGORY_SUPPORT);

    // The check path shares the planner, so it must see the query classes the
    // model-only generate just wrote, and nothing as missing or changed.
    $entries = (new DriftChecker)->check($planner->plan($request($querySpec(), $out)));

    expect($entries)->not->toBe([]);
    foreach ($entries as $entry) {
        expect($entry->status)->toBe(DriftStatus::InSync);
    }
});

it('emits query-skip warnings in a model-only run but keeps the scaffold header/cookie warnings out', function () use ($querySpec, $tempOut, $request) {
    $out = $tempOut();
    $planner = new GenerationPlanner;

    // Model-only: the data-layer diagnostics (skipped query parameters) are
    // kept, the scaffold-only diagnostics (header/cookie parameters the
    // controllers would not type) stay out, unchanged from before.
    $modelOnly = implode("\n", $planner->plan($request($querySpec(), $out))->warnings);
    expect($modelOnly)->toContain('query parameter "filter" was skipped')
        ->and($modelOnly)->not->toContain('header parameter(s)')
        ->and($modelOnly)->not->toContain('cookie parameter(s)');

    // With the scaffold enabled the collector warnings are merged in as before.
    $full = implode("\n", $planner->plan($request($querySpec(), $out, true, $out.'/Http', $out.'/routes/api.generated.php'))->warnings);
    expect($full)->toContain('header parameter(s) "X-Trace-Id" are not generated')
        ->and($full)->toContain('cookie parameter(s) "session" are not generated');
});

it('honors security.middleware_map in the planned routes file and surfaces the unmapped-scheme warning (#77)', function () use ($tempOut) {
    $out = $tempOut();
    $plan = (new GenerationPlanner)->plan(new GenerationRequest(
        spec: __DIR__.'/../../Fixtures/server/secured-petstore.yaml',
        output: $out,
        namespace: 'App\\Data',
        suffix: 'Data',
        maxDepth: 64,
        maxBytes: null,
        controllers: true,
        controllerPath: $out.'/Http',
        controllerNamespace: 'App\\Http\\Controllers\\Api',
        routes: true,
        routesPath: $out.'/routes/api.generated.php',
        securityMiddlewareMap: ['bearerAuth' => ['auth:sanctum']],
    ));

    $routes = $plan->filesByCategory(PlannedFile::CATEGORY_ROUTES)[0]->content;

    // The mapped global scheme lands on the inheriting route; the unmapped
    // apiKey override warns through the plan's merged diagnostics channel.
    expect($routes)->toContain("->name('index_2')->middleware(['auth:sanctum']);")
        ->and(implode("\n", $plan->warnings))->toContain('Security scheme "apiKey" is required by the spec but has no entry in security.middleware_map');
});

it('surfaces the 3.2 best-effort warnings through the shared plan channel (#103)', function () use ($tempOut, $request) {
    $spec = __DIR__.'/../../Fixtures/edge/openapi-3.2-constructs.yaml';
    $out = $tempOut();
    $planner = new GenerationPlanner;

    // Generate and check share this planner, so asserting the parser warnings
    // land in the plan's merged channel proves both surfaces see them.
    $plan = $planner->plan($request($spec, $out, true, $out.'/Http', $out.'/routes/api.generated.php'));
    $warnings = implode("\n", $plan->warnings);

    expect($warnings)->toContain('OpenAPI 3.2 is not fully supported yet')
        ->toContain('OpenAPI 3.2 `query` operation at paths./things was dropped')
        ->toContain('OpenAPI 3.2 `additionalOperations` at paths./things were dropped')
        ->toContain('OpenAPI 3.2 `itemSchema` at paths./things/stream.get.responses.200.content.application/jsonl was dropped');

    // The accepted parts of the document still generate: the GET operations
    // and the component schema come through, best-effort means degraded, not
    // empty. A written plan re-checks as in sync, warnings included on both runs.
    $writer = new PlanWriter;
    foreach ([PlannedFile::CATEGORY_DATA, PlannedFile::CATEGORY_SUPPORT, PlannedFile::CATEGORY_CONTROLLER, PlannedFile::CATEGORY_ROUTES] as $category) {
        $writer->write($plan, $category);
    }

    $replan = $planner->plan($request($spec, $out, true, $out.'/Http', $out.'/routes/api.generated.php'));
    expect($replan->warnings)->toBe($plan->warnings);

    foreach ((new DriftChecker)->check($replan) as $entry) {
        expect($entry->status)->toBe(DriftStatus::InSync);
    }
});

it('throws a PlanException when the spec is missing', function () use ($tempOut, $request) {
    (new GenerationPlanner)->plan($request('', $tempOut()));
})->throws(PlanException::class);

it('throws a PlanException when controllers are requested without a path', function () use ($serverSpec, $tempOut, $request) {
    (new GenerationPlanner)->plan($request($serverSpec(), $tempOut(), true, null, null));
})->throws(PlanException::class);

it('plans no stub files unless the request asks for them (generate and check never do)', function () use ($serverSpec, $tempOut, $request) {
    $out = $tempOut();
    $plan = (new GenerationPlanner)->plan($request($serverSpec(), $out, true, $out.'/Http', $out.'/routes/api.generated.php'));

    expect($plan->filesByCategory(PlannedFile::CATEGORY_STUB))->toBe([]);
});

it('plans one concrete stub per controller when the request asks for stubs (issue #78)', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    $plan = (new GenerationPlanner)->plan(new GenerationRequest(
        spec: $serverSpec(),
        output: $out,
        namespace: 'App\\Data',
        suffix: 'Data',
        maxDepth: 64,
        maxBytes: null,
        controllers: true,
        controllerPath: $out.'/Http',
        controllerNamespace: 'App\\Http\\Controllers\\Api',
        routes: true,
        routesPath: $out.'/routes/api.generated.php',
        stubs: true,
    ));

    $stubPaths = array_map(static fn (PlannedFile $f): string => $f->path, $plan->filesByCategory(PlannedFile::CATEGORY_STUB));

    // One stub per concrete controller, in the abstracts' directory, and the
    // generator-owned categories are still planned alongside (same planner,
    // same descriptors, so the stub list can never drift from the routes).
    expect($stubPaths)->toBe([$out.'/Http/PetController.php', $out.'/Http/UntaggedController.php'])
        ->and($plan->filesByCategory(PlannedFile::CATEGORY_CONTROLLER))->not->toBe([]);
});

it('throws a PlanException when stubs are requested with controllers disabled (issue #78)', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();
    (new GenerationPlanner)->plan(new GenerationRequest(
        spec: $serverSpec(),
        output: $out,
        namespace: 'App\\Data',
        suffix: 'Data',
        maxDepth: 64,
        maxBytes: null,
        controllers: false,
        controllerPath: $out.'/Http',
        controllerNamespace: 'App\\Http\\Controllers\\Api',
        routes: true,
        routesPath: $out.'/routes/api.generated.php',
        stubs: true,
    ));
})->throws(PlanException::class, 'Controller stubs extend the generated abstract controllers');

it('rejects two tags that differ only by case to avoid a filesystem write collision (#108)', function () use ($tempOut, $request) {
    $spec = __DIR__.'/../../Fixtures/server/case-clash-tags.yaml';
    $out = $tempOut();

    (new GenerationPlanner)->plan($request($spec, $out, true, $out.'/Http', $out.'/routes/api.generated.php'));
})->throws(PlanException::class, 'same path on a case-insensitive filesystem');
