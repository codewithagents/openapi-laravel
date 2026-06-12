<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Long / non-identifier property-name guard.
 *
 * A single object whose property names span the worst of the invalid-identifier
 * and length class: a 185-character name (well past 180), a kebab-case key
 * (`kebab-case-thing`, illegal as a bare PHP identifier), and a key starting
 * with `+` (`+1`, which PHP coerces to an int array key). The naming layer must
 * keep such a schema generable, compilable, and idempotent under both Laravel
 * Pint and PHPStan max, so the generated output never goes red in a consumer's
 * own CI through no fault of theirs.
 *
 * The fixture is deliberately kept out of the shared 130-spec corpus (it lives
 * under tests/Fixtures/edge/) so the corpus count and its real-world character
 * stay intact, while this guard still proves the edge class holds.
 */
const LONG_NAME_SPEC = __DIR__.'/../Fixtures/edge/long-and-non-identifier-names.yaml';

it('generates a compilable Data class for long and non-identifier property names', function () {
    $document = (new SpecParser)->parseFileToDocument(LONG_NAME_SPEC);
    $files = (new ModelGenerator)->generate($document);

    expect($files)->toHaveKey('EdgeNamesData');

    // Every emitted file is real PHP: token_get_all with TOKEN_PARSE is the
    // in-process equivalent of `php -l`.
    foreach ($files as $file) {
        try {
            token_get_all($file->code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $this->fail("Invalid PHP in {$file->filename()}: {$e->getMessage()}\n\n".$file->code);
        }
    }

    $code = $files['EdgeNamesData']->code;

    // The illegal wire names survive as #[MapName(...)] mappings onto legal PHP
    // property names rather than being dropped or producing a fatal identifier.
    expect($code)->toContain("#[MapName('kebab-case-thing')]")
        ->and($code)->toContain("#[MapName('+1')]")
        // The long name still carries a rule under its wire key.
        ->and($code)->toContain('this_is_a_pathologically_long_property_name');
});

it('generates byte-identical output for long and non-identifier names (deterministic)', function () {
    $document = (new SpecParser)->parseFileToDocument(LONG_NAME_SPEC);

    $first = (new ModelGenerator)->generate($document)['EdgeNamesData']->code;
    $second = (new ModelGenerator)->generate($document)['EdgeNamesData']->code;

    expect($second)->toBe($first);
});

it('keeps long and non-identifier-name output Pint-idempotent', function () {
    $document = (new SpecParser)->parseFileToDocument(LONG_NAME_SPEC);
    $files = (new ModelGenerator)->generate($document);

    $dir = sys_get_temp_dir().'/openapi-laravel-longname-pint-'.bin2hex(random_bytes(6));
    expect(mkdir($dir, 0700, true) || is_dir($dir))->toBeTrue("could not create temp dir {$dir}");

    foreach ($files as $file) {
        file_put_contents($dir.'/'.$file->filename(), $file->code);
    }

    $repoRoot = dirname(__DIR__, 2);
    $pint = $repoRoot.'/vendor/bin/pint';
    $config = $repoRoot.'/pint.json';

    try {
        $command = escapeshellarg($pint).' --test --config='.escapeshellarg($config).' '.escapeshellarg($dir).' 2>&1';
        exec($command, $output, $exitCode);
    } finally {
        foreach ($files as $file) {
            @unlink($dir.'/'.$file->filename());
        }
        @rmdir($dir);
    }

    expect($exitCode)->toBe(0, "Pint reformatted the long-name output (not idempotent):\n".implode("\n", $output));
});

it('keeps long and non-identifier-name output PHPStan-max-clean', function () {
    $repoRoot = dirname(__DIR__, 2);

    $document = (new SpecParser)->parseFileToDocument(LONG_NAME_SPEC);
    $files = (new ModelGenerator)->generate($document);

    $base = sys_get_temp_dir().'/openapi-laravel-longname-phpstan-'.bin2hex(random_bytes(6));
    $analyseDir = $base.'/code';
    expect(mkdir($analyseDir, 0700, true) || is_dir($analyseDir))->toBeTrue("could not create temp dir {$analyseDir}");

    foreach ($files as $file) {
        file_put_contents($analyseDir.'/'.$file->filename(), $file->code);
    }

    $neon = $base.'/phpstan.neon';
    file_put_contents($neon, implode("\n", [
        'includes:',
        '    - '.$repoRoot.'/vendor/larastan/larastan/extension.neon',
        '',
        'parameters:',
        '    level: max',
        '    paths:',
        '        - '.$analyseDir,
        '    bootstrapFiles:',
        '        - '.$repoRoot.'/vendor/autoload.php',
        '    tmpDir: '.$base.'/phpstan-cache',
        '',
    ]));

    $phpstan = $repoRoot.'/vendor/bin/phpstan';

    try {
        $command = escapeshellarg(PHP_BINARY).' -d memory_limit=2G '
            .escapeshellarg($phpstan).' analyse'
            .' --configuration='.escapeshellarg($neon)
            .' --no-progress --error-format=raw 2>&1';
        exec($command, $output, $exitCode);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }

    expect($exitCode)->toBe(0, "PHPStan max reported errors on the long-name output:\n".implode("\n", $output));
});
