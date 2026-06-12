<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * #104 T3: THE acceptance gate for the cebe replacement. For every corpus
 * spec (the `corpus_specs` dataset globs the entire tests/Fixtures/specs
 * directory, all 130 files, no exclusions) the full generation pipeline runs
 * twice: once through today's cebe path (decode, SchemaNormalizer, cebe
 * object model) and once through the new reader path (decode, OpenApiReader,
 * SpecArraySerializer round trip, cebe object model). Every generated file,
 * model, enum, support class, query/body Data class, controller, and routes
 * file, must be byte-identical, and the emitter warnings must match exactly.
 *
 * Anything the reader drops, moves, retypes, or coerces differently from the
 * SchemaNormalizer + cebe combination shows up here as a byte diff. Nothing
 * advances to Task 4 (migrating the emitter onto the typed graph) until this
 * gate is 100% green. The round-trip scaffolding (SpecArraySerializer, the
 * useNewReader flag) and this test are deleted in Task 7.
 */
it('generates byte-identical output via the cebe path and the new reader path', function (string $path) {
    [$cebeFiles, $cebeWarnings] = readerGatePipeline($path, useNewReader: false);
    [$readerFiles, $readerWarnings] = readerGatePipeline($path, useNewReader: true);

    $spec = basename($path);

    $missing = array_diff(array_keys($cebeFiles), array_keys($readerFiles));
    $unexpected = array_diff(array_keys($readerFiles), array_keys($cebeFiles));

    if ($missing !== [] || $unexpected !== []) {
        $this->fail(
            "Generated file set diverges for {$spec}.\n".
            'Missing on the reader path: ['.implode(', ', $missing)."]\n".
            'Unexpected on the reader path: ['.implode(', ', $unexpected).']'
        );
    }

    foreach ($cebeFiles as $filename => $code) {
        if ($readerFiles[$filename] !== $code) {
            $this->fail(
                "Byte divergence in {$filename} (from {$spec}):\n".
                readerGateFirstDifference($code, $readerFiles[$filename])
            );
        }
    }

    if ($cebeWarnings !== $readerWarnings) {
        $this->fail(
            "Warning divergence for {$spec}.\n".
            "cebe path:\n  ".implode("\n  ", $cebeWarnings)."\n".
            "reader path:\n  ".implode("\n  ", $readerWarnings)
        );
    }

    expect(true)->toBeTrue();
})->with('corpus_specs')->group('slow');

/**
 * The full generation pipeline (models, support classes, per-operation
 * query/body Data classes, controllers, routes), mirroring the
 * command/standalone wiring exactly as GenerateServerCorpusTest does, with
 * default options. Returns the generated code keyed by relative filename plus
 * the combined deterministic warning list.
 *
 * @return array{0: array<string, string>, 1: list<string>}
 */
function readerGatePipeline(string $path, bool $useNewReader): array
{
    $document = (new SpecParser(useNewReader: $useNewReader))->parseFile($path);

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

    return [$files, [...$generator->warnings(), ...$collector->warnings()]];
}

/**
 * A bounded human-readable report of the first differing line between two
 * generated files, so a gate failure never dumps whole files.
 */
function readerGateFirstDifference(string $expected, string $actual): string
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $max = max(count($expectedLines), count($actualLines));

    for ($i = 0; $i < $max; $i++) {
        $expectedLine = $expectedLines[$i] ?? '<missing line>';
        $actualLine = $actualLines[$i] ?? '<missing line>';

        if ($expectedLine !== $actualLine) {
            return sprintf(
                "First difference at line %d:\n  cebe path:   %s\n  reader path: %s",
                $i + 1,
                substr($expectedLine, 0, 200),
                substr($actualLine, 0, 200),
            );
        }
    }

    return 'Files differ in length or trailing bytes only.';
}
