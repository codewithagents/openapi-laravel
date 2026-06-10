<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\GenerationPlanner;
use CodeWithAgents\OpenApiLaravel\Console\GenerationRequest;
use CodeWithAgents\OpenApiLaravel\Console\PlanException;
use CodeWithAgents\OpenApiLaravel\Console\PlannedFile;
use CodeWithAgents\OpenApiLaravel\Console\PlanWriter;

$customerSpec = fn (): string => __DIR__.'/../../Fixtures/emitter/customer.json';
$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
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

it('throws a PlanException when the spec is missing', function () use ($tempOut, $request) {
    (new GenerationPlanner)->plan($request('', $tempOut()));
})->throws(PlanException::class);

it('throws a PlanException when controllers are requested without a path', function () use ($serverSpec, $tempOut, $request) {
    (new GenerationPlanner)->plan($request($serverSpec(), $tempOut(), true, null, null));
})->throws(PlanException::class);
