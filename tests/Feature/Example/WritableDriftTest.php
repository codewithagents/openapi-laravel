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
 * Drift guard for the writable-variant example: regenerate the Data classes,
 * abstract controller, and routes file from tests/Fixtures/server/writable.yaml
 * using the committed namespaces, and assert byte-identical output. This proves
 * the committed writable example is genuine generator output (not hand-edited)
 * and that the read/write-split generation is deterministic.
 */
const WRITABLE_DIR = __DIR__.'/Writable';

const WRITABLE_SPEC = __DIR__.'/../../Fixtures/server/writable.yaml';

const WRITABLE_DATA_NAMESPACE = 'CodeWithAgents\\OpenApiLaravel\\Tests\\Feature\\Example\\Writable\\Data';

const WRITABLE_CONTROLLER_NAMESPACE = 'CodeWithAgents\\OpenApiLaravel\\Tests\\Feature\\Example\\Writable\\Http';

function regenerateWritable(): array
{
    $document = (new SpecParser)->parseFile(WRITABLE_SPEC);

    $modelGenerator = new ModelGenerator(new GeneratorOptions(WRITABLE_DATA_NAMESPACE));
    $dataFiles = $modelGenerator->generate($document);
    $registry = $modelGenerator->registry();

    $serverOptions = new ServerOptions(WRITABLE_CONTROLLER_NAMESPACE, WRITABLE_DATA_NAMESPACE);
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

it('inlines no support classes for a spec that references none (#40)', function () {
    // The writable example uses no rule/transformer support class, so the inlined
    // support set is empty: only the classes a spec references are emitted, and
    // no `Support/` directory is committed.
    expect(regenerateWritable()['support'])->toBe([])
        ->and(is_dir(WRITABLE_DIR.'/Data/Support'))->toBeFalse();
});

it('regenerates writable Data classes byte-identical to the committed example', function () {
    $data = regenerateWritable()['data'];

    // Both the read and write variants must be produced.
    expect(array_keys($data))->toContain('WidgetData', 'WidgetWritableData');

    foreach ($data as $file) {
        $committed = WRITABLE_DIR.'/Data/'.$file->filename();

        expect(is_file($committed))->toBeTrue("Missing committed file: {$file->filename()}")
            ->and(file_get_contents($committed))->toBe($file->code);
    }
});

it('regenerates the abstract widgets controller byte-identical to the committed example', function () {
    $controllers = regenerateWritable()['controllers'];

    expect(array_keys($controllers))->toBe(['AbstractWidgetsController']);

    foreach ($controllers as $file) {
        $committed = WRITABLE_DIR.'/Http/'.$file->filename();

        expect(is_file($committed))->toBeTrue("Missing committed file: {$file->filename()}")
            ->and(file_get_contents($committed))->toBe($file->code);
    }
});

it('regenerates the writable routes file byte-identical to the committed example', function () {
    $routeFile = regenerateWritable()['routes'];
    $committed = WRITABLE_DIR.'/routes/api.generated.php';

    expect(file_get_contents($committed))->toBe($routeFile->code);
});

it('types the createWidget body param as the writable variant', function () {
    $controllers = regenerateWritable()['controllers'];
    $code = $controllers['AbstractWidgetsController']->code;

    // The headline guarantee: the write body uses the writable variant, the
    // response uses the read variant.
    expect($code)->toContain('abstract public function createWidget(WidgetWritableData $widget): WidgetData;')
        ->and($code)->toContain('abstract public function getWidgetById(int $widgetId): WidgetData;');
});
