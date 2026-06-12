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
 * directory; the 130 specs the v0.11.0 freeze saw, no exclusions among them)
 * the full generation pipeline runs
 * through the typed spec graph and the result is hashed: sha256 over every
 * generated file, sorted by filename, covering the exact name AND the full
 * byte content of each one, plus the merged warning list. The hash must equal
 * the frozen v0.11.0 baseline in tests/Fixtures/corpus-baseline-v0.11.0.json,
 * which was generated from a pristine v0.11.0 worktree (the cebe pipeline at
 * tag v0.11.0) with the byte-for-byte identical recipe.
 *
 * The OpenAPI 3.2 fixtures added in #104 T8 (POST-v0.11.0 additions, listed
 * in READER_BASELINE_POST_FREEZE_SPECS) are explicitly exempt: v0.11.0 never
 * saw them, so they cannot be in the frozen baseline, and the baseline file
 * must NOT be regenerated to include them. Their end-to-end coverage
 * (parse, warnings, hydration, generation, php -l, import resolution) lives
 * in OpenApi32CorpusTest; the coverage assertion below pins that every
 * exempt name exists on disk and is absent from the baseline, so the list
 * cannot rot into a silent skip.
 *
 * Anything the reader path drops, moves, retypes, coerces, or renames, in any
 * file, by even one byte, changes the spec's hash and fails here. The gate
 * outlived the migration (Task 7 removed the cebe dependency entirely): it
 * stays as the frozen-baseline proof that the reader pipeline emits exactly
 * what the v0.11.0 cebe pipeline emitted.
 *
 * Regenerating the baseline wholesale is only legitimate from v0.11.0 itself:
 *   git worktree add /tmp/openapi-laravel-v0110 v0.11.0
 * then run this exact pipeline + recipe there (cebe parseFile instead of
 * parseFileToDocument, everything else identical).
 *
 * INTENTIONAL post-freeze rebaseline (#108): exactly four specs carry a
 * post-#108 hash instead of the pristine v0.11.0 one, because v0.11.0 emitted
 * two `.php` files whose names differed only by letter case (e.g.
 * `HttpHealthCheckData.php` / `HTTPHealthCheckData.php`). On a case-insensitive
 * filesystem (macOS APFS default, Windows NTFS) the second write silently
 * clobbered the first, so the v0.11.0 output for these specs was actually
 * broken. The #108 fix dedupes class names case-insensitively, suffixing the
 * second of each colliding pair (`...Data_2.php`), which is the ONLY change to
 * these specs (the case-insensitive allocator diverges from the case-sensitive
 * one solely on a case-fold collision). The four are listed in
 * READER_BASELINE_REBASELINED_108 with the colliding pair, so the rebaseline is
 * auditable and not a silent baseline drift; every other spec stays the frozen
 * v0.11.0 freeze, byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#108 output
 * (case-insensitive class-name dedup), keyed by spec basename, with the
 * case-only-colliding class-name pair v0.11.0 emitted as one clobbered file.
 * Documentation only: the per-spec test below still compares against the JSON
 * baseline, which now holds these specs' post-#108 hashes.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_108 = [
    'docusign.json' => 'BccEmailArchive / BCCEmailArchive',
    'flickr.json' => 'PhotoUrls / PhotoURLs',
    'google_compute.json' => 'HttpHealthCheck / HTTPHealthCheck, HttpsHealthCheck / HTTPSHealthCheck',
    'zoom.json' => 'AccountSettingsTsp / AccountSettingsTSP, UserSettingsTsp / UserSettingsTSP',
];

/*
 * INTENTIONAL post-freeze rebaseline (#110): the fourteen specs in
 * READER_BASELINE_REBASELINED_110 carry a post-#110 hash instead of the
 * pristine v0.11.0 one, because they use `#/components/requestBodies/...`
 * refs, which v0.11.0 left as a warned Illuminate\Http\Request fallback.
 * Issue #110 resolves the component and routes it through the same
 * content-type logic an inline body takes: a wrapped schema `$ref` types the
 * param with the existing Data class, an inline object schema synthesizes ONE
 * shared `<Component>RequestData` class, and a non-object shape keeps the
 * fallback with a reworded component-grained warning. Every divergence is
 * therefore a strict improvement (typed params and removed/reworded
 * fallback warnings). docusign and zoom sit in BOTH lists: their hashes
 * carry the #108 case-insensitive dedup AND the #110 typed bodies. Every
 * spec outside the two rebaseline lists stays the frozen v0.11.0 freeze,
 * byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#110 output
 * (component request bodies resolved to typed Data params), keyed by spec
 * basename, with the dominant change for auditability. The per-spec test
 * below still compares against the JSON baseline, which now holds these
 * specs' post-#110 hashes; the coverage test pins that every listed name
 * exists on disk and in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_110 = [
    'bitbucket.json' => 'schema-$ref component bodies typed against existing Data classes',
    'clevercloud.json' => 'schema-$ref component bodies typed against existing Data classes',
    'clicksend.json' => 'inline-object component bodies synthesize 7 shared classes',
    'docker.json' => 'array-shaped component body keeps the fallback with the component-grained warning',
    'docusign.json' => 'schema-$ref component bodies typed against existing Data classes (hash also carries the #108 case-insensitive dedup)',
    'reverb.json' => 'inline-object component bodies synthesize 3 shared classes',
    'sendgrid.json' => 'mixed: schema-$ref bodies typed, 4 non-object bodies keep the warned fallback',
    'slack.json' => 'schema-$ref component bodies typed against existing Data classes',
    'snyk.json' => 'inline-object component bodies synthesize 6 shared classes',
    'square.json' => 'schema-$ref component bodies typed against existing Data classes',
    'trello.json' => 'schema-$ref component bodies typed against existing Data classes',
    'xero.json' => 'schema-$ref component body typed against the existing Data class',
    'zoom.json' => 'inline-object component bodies synthesize 3 shared classes (hash also carries the #108 case-insensitive dedup)',
    'zuora.json' => 'schema-$ref component bodies typed against existing Data classes',
];

/*
 * INTENTIONAL post-freeze rebaseline (#116): the fourteen specs in
 * READER_BASELINE_REBASELINED_116 carry a post-#116 hash, because their
 * generated output (or warning list) involves response `$ref`s, which
 * v0.11.0 left as a warned JsonResponse fallback. Issue #116 resolves a
 * `#/components/responses/...` ref and routes it through the same
 * content-type logic an inline response takes: a wrapped schema `$ref` types
 * the return with the existing Data class, an inline object schema
 * synthesizes ONE shared `<Component>ResponseData` class (READ variant), and
 * a non-object shape keeps the fallback with a reworded component-grained
 * warning. A response `$ref` that is NOT a resolvable component response
 * (brex and digitalocean point into `#/paths/...`) keeps the fallback with
 * the reworded unresolvable warning, which alone changes those two hashes.
 * Every divergence is therefore a strict improvement (typed returns and
 * removed/reworded fallback warnings). clevercloud, sendgrid, and xero sit
 * in BOTH the #110 and #116 lists: their hashes carry both rebaselines.
 * Every spec outside the rebaseline lists stays the frozen v0.11.0 freeze,
 * byte for byte.
 */

/**
 * Specs whose frozen hash was deliberately updated to the post-#116 output
 * (component responses resolved to typed returns), keyed by spec basename,
 * with the dominant change for auditability. The per-spec test below still
 * compares against the JSON baseline, which now holds these specs' post-#116
 * hashes; the coverage test pins that every listed name exists on disk and
 * in the baseline, so the list cannot rot.
 *
 * @var array<string, string>
 */
const READER_BASELINE_REBASELINED_116 = [
    'ably.json' => 'schema-$ref component responses typed against existing Data classes',
    'amadeus.json' => 'inline-object component responses synthesize 2 shared classes',
    'brex.json' => 'unresolvable #/paths/... response $refs keep the fallback with the reworded warning',
    'circleci.json' => 'schema-$ref component responses typed against existing Data classes',
    'clevercloud.json' => 'schema-$ref component responses typed against existing Data classes (hash also carries #110)',
    'digitalocean.json' => 'unresolvable #/paths/... response $refs keep the fallback with the reworded warning',
    'github.json' => 'inline-object component responses synthesize 3 shared classes, schema-$ref ones typed',
    'here_positioning.json' => 'inline-object component response synthesizes 1 shared class',
    'sendgrid.json' => 'schema-$ref component responses typed against existing Data classes (hash also carries #110)',
    'soundcloud.json' => 'mixed: 1 inline-object component response synthesizes a shared class (plus a nested class), 17 non-object ones keep the warned fallback',
    'spotify.yaml' => 'inline-object component responses synthesize 17 shared classes, 8 non-object ones keep the warned fallback',
    'wayback.json' => 'schema-$ref component responses typed against existing Data classes',
    'worldtime.json' => 'schema-$ref component responses typed against existing Data classes',
    'xero.json' => 'schema-$ref component responses typed against existing Data classes (hash also carries #110)',
];

/**
 * Corpus specs added AFTER the v0.11.0 baseline freeze (#104 T8: the OpenAPI
 * 3.2 fixtures). The frozen baseline cannot contain them by definition, so
 * the per-spec comparison exempts exactly these names; everything else in
 * the glob must hash-match the freeze. OpenApi32CorpusTest owns their
 * end-to-end coverage.
 *
 * @var array<string, true>
 */
const READER_BASELINE_POST_FREEZE_SPECS = [
    'openapi-3.2-cdn-additional-operations.yaml' => true,
    'openapi-3.2-logstream-item-schema.yaml' => true,
    'openapi-3.2-museum.yaml' => true,
    'openapi-3.2-payments-default-mapping.yaml' => true,
    'openapi-3.2-query-flights.yaml' => true,
];

it('generates output byte-identical to the frozen v0.11.0 baseline', function (string $path) {
    $baseline = readerBaselineHashes();
    $spec = basename($path);

    if (isset(READER_BASELINE_POST_FREEZE_SPECS[$spec])) {
        $this->markTestSkipped(
            "{$spec} was added after the v0.11.0 baseline freeze (#104 T8, OpenAPI 3.2 corpus); ".
            'it is exempt by READER_BASELINE_POST_FREEZE_SPECS and covered end-to-end by OpenApi32CorpusTest.'
        );
    }

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

    // Every exempt post-freeze spec must actually exist on disk (no stale
    // exemptions) and the baseline must consist of exactly the glob minus
    // the exemptions: the frozen file gains nothing and loses nothing.
    $frozen = [];
    foreach ($specs as $spec) {
        if (! isset(READER_BASELINE_POST_FREEZE_SPECS[$spec])) {
            $frozen[] = $spec;
        }
    }
    expect(array_intersect_key(READER_BASELINE_POST_FREEZE_SPECS, array_flip($specs)))
        ->toHaveCount(count(READER_BASELINE_POST_FREEZE_SPECS))
        ->and(array_keys(readerBaselineHashes()))->toBe($frozen);

    // Every spec rebaselined for #108 must still exist on disk and carry a hash
    // in the baseline, so the documented rebaseline list cannot rot into a
    // stale reference to a removed or post-freeze-exempt spec.
    $baseline = readerBaselineHashes();
    foreach (array_keys(READER_BASELINE_REBASELINED_108) as $rebaselined) {
        expect($specs)->toContain($rebaselined)
            ->and($baseline)->toHaveKey($rebaselined)
            ->and(READER_BASELINE_POST_FREEZE_SPECS)->not->toHaveKey($rebaselined);
    }

    // Every spec rebaselined for #110 or #116 must still exist on disk and
    // carry a hash in the baseline (it is an update, not an exemption): a
    // renamed or deleted spec would make the documented rebaseline lists rot
    // silently.
    foreach ([...array_keys(READER_BASELINE_REBASELINED_110), ...array_keys(READER_BASELINE_REBASELINED_116)] as $spec) {
        expect($specs)->toContain($spec)
            ->and($baseline)->toHaveKey($spec);
    }
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
        ...array_values($generator->responseFiles()),
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
