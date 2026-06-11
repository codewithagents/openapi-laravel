<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Drift guard: regenerate the demo's Data classes, abstract controllers, and
 * routes file from examples/petstore/openapi.yaml using the same namespaces the
 * demo was generated with, and assert the output is byte-identical to the
 * committed files. This proves the committed demo is genuinely generated (not
 * hand-edited) and that the generator is deterministic.
 */
const DEMO_DIR = __DIR__.'/../../../examples/petstore';

const DATA_NAMESPACE = 'CodeWithAgents\\OpenApiLaravel\\Examples\\Petstore\\Data';

const CONTROLLER_NAMESPACE = 'CodeWithAgents\\OpenApiLaravel\\Examples\\Petstore\\Http\\Controllers\\Api';

function regenerateDemo(): array
{
    $document = (new SpecParser)->parseFile(DEMO_DIR.'/openapi.yaml');

    $modelGenerator = new ModelGenerator(new GeneratorOptions(DATA_NAMESPACE));
    $dataFiles = $modelGenerator->generate($document);
    $registry = $modelGenerator->registry();

    $serverOptions = new ServerOptions(CONTROLLER_NAMESPACE, DATA_NAMESPACE);
    // Collect once, share with both generators (mirrors the command/standalone wiring).
    $descriptors = (new OperationCollector($serverOptions, $registry))->collect($document);
    $controllerFiles = (new ControllerGenerator($serverOptions))->generate($descriptors);
    $routeFile = (new RouteGenerator($serverOptions))->generate($descriptors);

    return [
        'data' => $dataFiles,
        'support' => $modelGenerator->supportFiles(),
        'controllers' => $controllerFiles,
        'routes' => $routeFile,
    ];
}

it('regenerates Data classes byte-identical to the committed demo', function () {
    foreach (regenerateDemo()['data'] as $file) {
        $committed = DEMO_DIR.'/Data/'.$file->filename();

        expect(is_file($committed))->toBeTrue("Missing committed file: {$file->filename()}")
            ->and(file_get_contents($committed))->toBe($file->code);
    }
});

it('regenerates the inlined support classes byte-identical to the committed demo (#40)', function () {
    $support = regenerateDemo()['support'];

    // The demo's date-time field drives exactly one inlined support class. It is
    // owned, committed output, living in the consumer's own Support namespace.
    expect(array_keys($support))->toBe(['Rfc3339DateTimeRule']);

    foreach ($support as $file) {
        $committed = DEMO_DIR.'/Data/Support/'.$file->filename();

        expect(is_file($committed))->toBeTrue("Missing committed support file: {$file->filename()}")
            ->and(file_get_contents($committed))->toBe($file->code)
            // The inlined copy lives in the consumer's namespace and carries no
            // import of the generator's own Support namespace: fully self-contained.
            ->and($file->code)->toContain('namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Support;')
            ->and($file->code)->not->toContain('CodeWithAgents\OpenApiLaravel\Support');
    }
});

it('regenerates abstract controllers byte-identical to the committed demo', function () {
    foreach (regenerateDemo()['controllers'] as $file) {
        $committed = DEMO_DIR.'/Http/Controllers/Api/'.$file->filename();

        expect(is_file($committed))->toBeTrue("Missing committed file: {$file->filename()}")
            ->and(file_get_contents($committed))->toBe($file->code);
    }
});

it('regenerates the routes file byte-identical to the committed demo', function () {
    $routeFile = regenerateDemo()['routes'];
    $committed = DEMO_DIR.'/routes/api.generated.php';

    expect(file_get_contents($committed))->toBe($routeFile->code);
});

/**
 * List the basenames matching a glob, failing loudly if the directory is
 * missing or unreadable rather than passing vacuously on an empty result.
 *
 * @return list<string>
 */
function demoBasenames(string $dir, string $pattern): array
{
    expect(is_dir($dir))->toBeTrue("Demo directory missing or unreadable: {$dir}");

    $matches = glob($dir.'/'.$pattern);
    expect($matches)->toBeArray("glob failed for {$dir}/{$pattern}");

    return array_values(array_map('basename', $matches));
}

it('has no committed generated file that the generator would not produce', function () {
    $regenerated = regenerateDemo();

    $expectedData = array_map(fn ($f) => $f->filename(), $regenerated['data']);
    $actualData = demoBasenames(DEMO_DIR.'/Data', '*.php');
    sort($expectedData);
    sort($actualData);
    expect($actualData)->toBe($expectedData);

    // Abstract* files only: concrete controllers and the store are hand-written.
    $expectedAbstract = array_map(fn ($f) => $f->filename(), $regenerated['controllers']);
    $actualAbstract = demoBasenames(DEMO_DIR.'/Http/Controllers/Api', 'Abstract*.php');
    sort($expectedAbstract);
    sort($actualAbstract);
    expect($actualAbstract)->toBe($expectedAbstract);
});
