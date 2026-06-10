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
    $controllerFiles = (new ControllerGenerator($serverOptions, $registry))->generate($document);
    $descriptors = (new OperationCollector($serverOptions, $registry))->collect($document);
    $routeFile = (new RouteGenerator($serverOptions))->generate($descriptors);

    return [
        'data' => $dataFiles,
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

it('has no committed generated file that the generator would not produce', function () {
    $regenerated = regenerateDemo();

    $expectedData = array_map(fn ($f) => $f->filename(), $regenerated['data']);
    $actualData = array_map('basename', glob(DEMO_DIR.'/Data/*.php') ?: []);
    sort($expectedData);
    sort($actualData);
    expect($actualData)->toBe($expectedData);

    // Abstract* files only: concrete controllers and the store are hand-written.
    $expectedAbstract = array_map(fn ($f) => $f->filename(), $regenerated['controllers']);
    $actualAbstract = array_map('basename', glob(DEMO_DIR.'/Http/Controllers/Api/Abstract*.php') ?: []);
    sort($expectedAbstract);
    sort($actualAbstract);
    expect($actualAbstract)->toBe($expectedAbstract);
});
