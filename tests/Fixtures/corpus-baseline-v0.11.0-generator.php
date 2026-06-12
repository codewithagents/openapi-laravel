<?php

declare(strict_types=1);
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

// Generate the frozen v0.11.0 corpus baseline: one sha256 per corpus spec over
// every generated file (sorted by filename, name + full byte content) plus the
// merged warning list. Run against the v0.11.0 worktree src; dependencies come
// from the main repo's vendor (composer.lock is identical between the two).
//
// Usage: php baseline-v0110.php <worktree-root> <vendor-root> <output.json>

$root = $argv[1];
$vendorRoot = $argv[2];
$output = $argv[3];

require $vendorRoot.'/vendor/autoload.php';

// The worktree's own src must win over the main repo's: prepend a PSR-4
// loader for the package namespace AFTER composer registered its (itself
// prepended) autoloader, so this one runs first.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CodeWithAgents\\OpenApiLaravel\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, prepend: true);

$specs = glob($root.'/tests/Fixtures/specs/*');
sort($specs, SORT_STRING);

$baseline = [];

foreach ($specs as $path) {
    $parser = new SpecParser;
    $document = $parser->parseFile($path);

    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);

    $options = new ServerOptions;
    $collector = new OperationCollector($options, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($document);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);
    $routes = (new RouteGenerator($options))->generate($descriptors);

    $files = [];
    foreach ([
        ...array_values($modelFiles),
        ...array_values($generator->supportFiles()),
        ...array_values($generator->queryFiles()),
        ...array_values($generator->bodyFiles()),
        ...array_values($controllers),
        $routes,
    ] as $file) {
        $files[$file->filename()] = $file->code;
    }

    $warnings = [...$parser->warnings(), ...$generator->warnings(), ...$collector->warnings()];

    ksort($files, SORT_STRING);
    $ctx = hash_init('sha256');
    foreach ($files as $name => $code) {
        hash_update($ctx, $name."\0".$code."\0");
    }
    foreach ($warnings as $warning) {
        hash_update($ctx, 'warning:'.$warning."\0");
    }

    $baseline[basename($path)] = hash_final($ctx);
    fwrite(STDERR, basename($path)."\n");
}

ksort($baseline, SORT_STRING);
file_put_contents($output, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
