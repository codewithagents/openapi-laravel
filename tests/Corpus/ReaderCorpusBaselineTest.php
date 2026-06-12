<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * #104 T4: THE acceptance gate for the cebe replacement, restructured from
 * the T3 dual-path comparison (which died when the emitter stopped accepting
 * the cebe object model) into a frozen-baseline check. For every corpus spec
 * (the `corpus_specs` dataset globs the entire tests/Fixtures/specs
 * directory, all 130 files, no exclusions) the full generation pipeline runs
 * through the typed spec graph and the result is hashed: sha256 over every
 * generated file, sorted by filename, covering the exact name AND the full
 * byte content of each one, plus the merged warning list. The hash must equal
 * the frozen v0.11.0 baseline in tests/Fixtures/corpus-baseline-v0.11.0.json,
 * which was generated from a pristine v0.11.0 worktree (the cebe pipeline at
 * tag v0.11.0) with the byte-for-byte identical recipe.
 *
 * Anything the reader path drops, moves, retypes, coerces, or renames, in any
 * file, by even one byte, changes the spec's hash and fails here. The gate
 * outlived the migration (Task 7 removed the cebe dependency entirely): it
 * stays as the frozen-baseline proof that the reader pipeline emits exactly
 * what the v0.11.0 cebe pipeline emitted.
 *
 * Regenerating the baseline is only legitimate from v0.11.0 itself:
 *   git worktree add /tmp/openapi-laravel-v0110 v0.11.0
 * then run this exact pipeline + recipe there (cebe parseFile instead of
 * parseFileToDocument, everything else identical).
 */
it('generates output byte-identical to the frozen v0.11.0 baseline', function (string $path) {
    $baseline = readerBaselineHashes();
    $spec = basename($path);

    expect($baseline)->toHaveKey($spec);

    [$files, $warnings] = readerBaselinePipeline($path);

    expect(readerBaselineHash($files, $warnings))->toBe(
        $baseline[$spec],
        "Generated output for {$spec} diverged from the frozen v0.11.0 baseline (".count($files).' files, '.count($warnings).' warnings hashed).',
    );
})->with('corpus_specs')->group('slow');

it('covers every corpus spec in the frozen baseline, nothing more', function () {
    $specs = array_map(
        static fn (string $path): string => basename($path),
        glob(__DIR__.'/../Fixtures/specs/*') ?: [],
    );
    sort($specs, SORT_STRING);

    expect(array_keys(readerBaselineHashes()))->toBe($specs);
});

/**
 * The frozen v0.11.0 per-spec output hashes, keyed by spec basename, sorted.
 *
 * @return array<string, string>
 */
function readerBaselineHashes(): array
{
    /** @var array<string, string> $baseline */
    $baseline = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/corpus-baseline-v0.11.0.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $baseline;
}

/**
 * The full generation pipeline (models, support classes, per-operation
 * query/body Data classes, controllers, routes), mirroring the
 * command/standalone wiring exactly as GenerateServerCorpusTest does, with
 * default options. The whole pipeline (model layer AND operation collector)
 * consumes the one typed spec graph, exactly like GenerationPlanner since
 * Task 5. Returns the generated code keyed by relative filename plus the
 * combined deterministic warning list.
 *
 * @return array{0: array<string, string>, 1: list<string>}
 */
function readerBaselinePipeline(string $path): array
{
    $parser = new SpecParser;
    $document = $parser->parseFileToDocument($path);

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

    return [$files, [...$parser->warnings(), ...$generator->warnings(), ...$collector->warnings()]];
}

/**
 * The frozen baseline recipe: sha256 over every generated file sorted by
 * filename (name and full byte content both feed the hash, NUL-delimited so
 * no concatenation ambiguity exists), then every warning in order. Must stay
 * byte-for-byte identical to the recipe that froze the v0.11.0 baseline.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $warnings
 */
function readerBaselineHash(array $files, array $warnings): string
{
    ksort($files, SORT_STRING);

    $ctx = hash_init('sha256');
    foreach ($files as $name => $code) {
        hash_update($ctx, $name."\0".$code."\0");
    }
    foreach ($warnings as $warning) {
        hash_update($ctx, 'warning:'.$warning."\0");
    }

    return hash_final($ctx);
}
