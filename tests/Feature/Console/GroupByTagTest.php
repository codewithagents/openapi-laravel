<?php

declare(strict_types=1);

/*
 * The opt-in tag-grouped data layout (issue #93) through the artisan surface:
 * the --group-by-tag flag, the mirrored output.group_by_tag config key, the
 * strict flag-over-config precedence, the conflicting-flags error, the
 * default-unchanged guarantee, grouped-mode determinism, and generate/check
 * lockstep through the shared planner.
 */

$serverSpec = fn (): string => __DIR__.'/../../Fixtures/server/petstore.yaml';
$tempOut = fn (): string => sys_get_temp_dir().'/oal_group_'.uniqid();

$configurePaths = function (string $out): array {
    $controllerOut = $out.'/Http/Controllers/Api';
    $routesOut = $out.'/routes/api.generated.php';
    config()->set('openapi-laravel.controllers.path', $controllerOut);
    config()->set('openapi-laravel.routes.path', $routesOut);

    return [$controllerOut, $routesOut];
};

it('emits the grouped layout with --group-by-tag: per-tag directories, namespaces, and controller imports', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--group-by-tag' => true,
    ])->assertSuccessful();

    // The petstore's Pet schema (and the per-operation query classes of the
    // pet-tagged operations) move into Pet/; the untagged /health operation
    // references no schema, so nothing lands in an Untagged/ directory.
    expect(is_file($out.'/Pet/PetData.php'))->toBeTrue()
        ->and(is_file($out.'/Pet/PetWritableData.php'))->toBeTrue()
        ->and(is_file($out.'/Pet/ListPetsQueryData.php'))->toBeTrue()
        ->and(is_file($out.'/PetData.php'))->toBeFalse()
        ->and((string) file_get_contents($out.'/Pet/PetData.php'))->toContain('namespace App\Data\Pet;')
        ->and((string) file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('use App\Data\Pet\PetData;')
        ->and((string) file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('use App\Data\Pet\ListPetsQueryData;');
});

it('keeps the default output unchanged when the flag is absent', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    expect(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_dir($out.'/Pet'))->toBeFalse()
        ->and((string) file_get_contents($out.'/PetData.php'))->toContain("namespace App\Data;\n");
});

it('produces byte-identical output for a default run and an explicit --no-group-by-tag run', function () use ($serverSpec, $tempOut, $configurePaths) {
    $defaultOut = $tempOut();
    [$defaultControllers] = $configurePaths($defaultOut);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $defaultOut])->assertSuccessful();

    $offOut = $tempOut();
    [$offControllers] = $configurePaths($offOut);
    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $offOut,
        '--no-group-by-tag' => true,
    ])->assertSuccessful();

    expect(file_get_contents($offOut.'/PetData.php'))->toBe(file_get_contents($defaultOut.'/PetData.php'))
        ->and(file_get_contents($offOut.'/ListPetsQueryData.php'))->toBe(file_get_contents($defaultOut.'/ListPetsQueryData.php'))
        ->and(file_get_contents($offControllers.'/AbstractPetController.php'))
        ->toBe(file_get_contents($defaultControllers.'/AbstractPetController.php'));
});

it('generates deterministically in grouped mode: two runs write byte-identical trees', function () use ($serverSpec, $tempOut, $configurePaths) {
    $collect = function (string $out): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[substr((string) $file, strlen($out))] = (string) file_get_contents((string) $file);
        }
        ksort($files);

        return $files;
    };

    $first = $tempOut();
    $configurePaths($first);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $first, '--group-by-tag' => true])->assertSuccessful();

    $second = $tempOut();
    $configurePaths($second);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $second, '--group-by-tag' => true])->assertSuccessful();

    expect($collect($first))->toBe($collect($second));
});

it('lets the output.group_by_tag config key enable the layout without a flag', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.output.group_by_tag', true);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    expect(is_file($out.'/Pet/PetData.php'))->toBeTrue();
});

it('lets --no-group-by-tag override a config that enables the layout', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.output.group_by_tag', true);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--no-group-by-tag' => true,
    ])->assertSuccessful();

    expect(is_file($out.'/PetData.php'))->toBeTrue()
        ->and(is_dir($out.'/Pet'))->toBeFalse();
});

it('rejects --group-by-tag combined with --no-group-by-tag with exit 2 and writes nothing', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--group-by-tag' => true,
        '--no-group-by-tag' => true,
    ])->assertExitCode(2);

    expect(is_dir($out))->toBeFalse();
});

it('keeps generate and check in lockstep: check needs the same flag to be in sync', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.spec', $serverSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate', ['--group-by-tag' => true])->assertSuccessful();

    // Same flag: byte-for-byte in sync, including the grouped subdirectories.
    $this->artisan('openapi:check', ['--group-by-tag' => true])
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);

    // Without the flag the planner computes the flat layout, so the grouped
    // files on disk register as drift (the flat paths are missing).
    $this->artisan('openapi:check')->assertExitCode(1);
});

it('rejects the conflicting flag pair on openapi:check with exit 2', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.spec', $serverSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:check', [
        '--group-by-tag' => true,
        '--no-group-by-tag' => true,
    ])->assertExitCode(2);
});

it('combines with --laravel-conventions without the two options interfering', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--group-by-tag' => true,
        '--laravel-conventions' => true,
    ])->assertSuccessful();

    // Conventional method names AND grouped data classes: the Data layer
    // stays operationId-derived (ListPetsQueryData, not IndexQueryData) but
    // moves into the tag directory.
    expect((string) file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('abstract public function index(ListPetsQueryData $query)')
        ->and(is_file($out.'/Pet/ListPetsQueryData.php'))->toBeTrue();
});
