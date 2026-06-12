<?php

declare(strict_types=1);
use Symfony\Component\Console\Exception\InvalidOptionException;

/*
 * The tag-grouped data layout (issue #93) is the generator's only layout:
 * per-tag directories, namespaces, and controller imports come out of a plain
 * `openapi:generate` with no flag and no config key. These tests pin the
 * grouped layout as the default, its determinism, the interplay with the
 * conventional method names, and generate/check lockstep through the shared
 * planner.
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

it('emits the grouped layout by default: per-tag directories, namespaces, and controller imports', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    // The petstore's Pet schema (and the per-operation query classes of the
    // pet-tagged operations) live in Pet/; the untagged /health operation
    // references no schema, so nothing lands in an Untagged/ directory.
    expect(is_file($out.'/Pet/PetData.php'))->toBeTrue()
        ->and(is_file($out.'/Pet/PetWritableData.php'))->toBeTrue()
        ->and(is_file($out.'/Pet/ListPetsQueryData.php'))->toBeTrue()
        ->and(is_file($out.'/PetData.php'))->toBeFalse()
        ->and(is_dir($out.'/Untagged'))->toBeFalse()
        ->and((string) file_get_contents($out.'/Pet/PetData.php'))->toContain('namespace App\Data\Pet;')
        ->and((string) file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('use App\Data\Pet\PetData;')
        ->and((string) file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('use App\Data\Pet\ListPetsQueryData;');
});

it('generates deterministically: two runs write byte-identical grouped trees', function () use ($serverSpec, $tempOut, $configurePaths) {
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
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $first])->assertSuccessful();

    $second = $tempOut();
    $configurePaths($second);
    $this->artisan('openapi:generate', ['--spec' => $serverSpec(), '--output' => $second])->assertSuccessful();

    expect($collect($first))->toBe($collect($second));
});

it('keeps generate and check in lockstep over the grouped tree', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    $configurePaths($out);
    config()->set('openapi-laravel.spec', $serverSpec());
    config()->set('openapi-laravel.output.path', $out);

    $this->artisan('openapi:generate')->assertSuccessful();

    $this->artisan('openapi:check')
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);

    // A tampered grouped file registers as drift like any other owned file.
    $path = $out.'/Pet/PetData.php';
    file_put_contents($path, file_get_contents($path).' ');
    $this->artisan('openapi:check')->assertExitCode(1);
});

it('rejects the removed --group-by-tag flag instead of silently accepting it', function () use ($serverSpec, $tempOut) {
    $out = $tempOut();

    expect(fn () => $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
        '--group-by-tag' => true,
    ]))->toThrow(InvalidOptionException::class);
});

it('keeps the Data layer operationId-derived inside the tag directories', function () use ($serverSpec, $tempOut, $configurePaths) {
    $out = $tempOut();
    [$controllerOut] = $configurePaths($out);

    $this->artisan('openapi:generate', [
        '--spec' => $serverSpec(),
        '--output' => $out,
    ])->assertSuccessful();

    // The query class name comes from the operationId (ListPetsQueryData,
    // never IndexQueryData): Data classes share one global allocator, so the
    // conventional method names (issue #94) must not leak into them.
    expect(is_file($out.'/Pet/ListPetsQueryData.php'))->toBeTrue()
        ->and(glob($out.'/Pet/*QueryData.php'))->not->toBe([])
        ->and((string) file_get_contents($controllerOut.'/AbstractPetController.php'))
        ->toContain('ListPetsQueryData');
});
