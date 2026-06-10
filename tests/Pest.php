<?php

declare(strict_types=1);
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;

/*
 * Feature tests boot a Testbench Laravel app (service provider, artisan command).
 * Unit tests (Parser, Naming, Emitter) run as plain PHP, no container needed.
 */
uses(TestCase::class)->in('Feature');

/*
 * The real-world spec corpus, shared by the parse and generate gates.
 */
dataset('corpus_specs', function () {
    $files = glob(__DIR__.'/Fixtures/specs/*.{json,yaml,yml}', GLOB_BRACE) ?: [];

    $cases = [];
    foreach ($files as $file) {
        $cases[basename($file)] = [$file];
    }

    return $cases;
});

/**
 * Class short-names that may appear in a generated signature WITHOUT a matching
 * `use` import. Deliberately limited to PHP scalar and pseudo types, which are
 * language built-ins that can never be imported.
 *
 * Framework classes the emitters reference by short name (Request, JsonResponse,
 * DataCollection, Data, Optional) are intentionally NOT allowlisted: the
 * emitters always import them, so requiring an import here is what lets this
 * gate catch the historic unimported-`Request` bug and its whole class.
 *
 * @var array<string, true>
 */
const IMPORT_RESOLUTION_ALLOWLIST = [
    'int' => true, 'float' => true, 'string' => true, 'bool' => true,
    'array' => true, 'object' => true, 'mixed' => true, 'callable' => true,
    'iterable' => true, 'void' => true, 'null' => true, 'false' => true,
    'true' => true, 'never' => true, 'self' => true, 'static' => true,
    'parent' => true,
];

/**
 * Static import-resolution check over generated PHP. token_get_all(TOKEN_PARSE)
 * accepts a syntactically valid file that references an unimported, undefined
 * class (e.g. `Request $request` with no `use Illuminate\Http\Request;`), so
 * the syntax gate cannot catch that whole class of bug. This check does.
 *
 * For one generated file it collects: the class short-names brought in by `use`
 * statements, then asserts that every class-like short-name used as a parameter
 * type hint or return type in a function/method signature is either imported,
 * defined somewhere in the generated output ($definedNames), or on the
 * allowlist. The scan is deliberately scoped to signatures (the only place the
 * emitters write class references) to stay fast and false-positive free.
 *
 * Returns the list of unresolved short-names (empty when the file is clean).
 *
 * @param  array<string, true>  $definedNames  short-names defined across the whole generated set, lower-cased
 * @return list<string>
 */
function unresolvedSignatureTypes(string $code, array $definedNames): array
{
    $tokens = token_get_all($code, TOKEN_PARSE);

    // Pass 1: collect short-names imported via `use ...;` (ignoring group/use
    // function/use const, which the emitters never produce).
    $imported = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
            continue;
        }

        // Gather the import statement up to the terminating semicolon.
        $segments = [];
        $current = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === ';' || $token === '{') {
                break;
            }
            if ($token === ',') {
                $segments[] = $current;
                $current = '';

                continue;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $current .= $token[1];
            }
        }
        $segments[] = $current;

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $parts = explode('\\', $segment);
            $short = end($parts);
            if ($short !== false && $short !== '') {
                $imported[strtolower($short)] = true;
            }
        }
    }

    // Pass 2: find each function signature's parameter list and return type,
    // collecting the class-like short-names they reference.
    $used = [];
    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        // Walk to the opening parenthesis of the parameter list.
        $j = $i + 1;
        while ($j < $count && $tokens[$j] !== '(') {
            $j++;
        }
        if ($j >= $count) {
            continue;
        }

        // Consume the balanced parameter list, then the return type up to the
        // method body ('{') or abstract terminator (';'). Every class-like name
        // in this span (param type hints and the return type) is a reference
        // the file must be able to resolve.
        $depth = 0;
        for (; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token === '(') {
                $depth++;

                continue;
            }
            if ($token === ')') {
                $depth--;

                continue;
            }
            if ($depth === 0 && ($token === '{' || $token === ';')) {
                break;
            }

            // A class-like name is a T_STRING / qualified name whose short
            // segment starts uppercase (scalars like int/string arrive as
            // lowercase T_STRING and are skipped here, then allowlisted anyway).
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $parts = explode('\\', $token[1]);
                $short = end($parts);
                if ($short !== false && $short !== '' && ctype_upper($short[0])) {
                    $used[$short] = true;
                }
            }
        }
    }

    $unresolved = [];
    foreach (array_keys($used) as $short) {
        $key = strtolower($short);
        if (isset($imported[$key]) || isset($definedNames[$key]) || isset(IMPORT_RESOLUTION_ALLOWLIST[$key])) {
            continue;
        }
        $unresolved[] = $short;
    }

    return $unresolved;
}

/**
 * Collect the lower-cased short-names of every class/interface/trait/enum
 * defined across a set of generated files, so cross-file references (a
 * controller param typed against a generated Data class in another file)
 * resolve in the import check.
 *
 * @param  iterable<GeneratedFile>  $files
 * @return array<string, true>
 */
function definedClassNames(iterable $files): array
{
    $defined = [];
    foreach ($files as $file) {
        $tokens = token_get_all($file->code, TOKEN_PARSE);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }
            // The class name is the next T_STRING (skipping whitespace).
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $defined[strtolower($tokens[$j][1])] = true;
                }
                break;
            }
        }
    }

    return $defined;
}

/**
 * Corpus specs the `php -l` gate deliberately exempts, with the reason.
 *
 * These are pre-existing generator residuals: `php -l` (a real compile, unlike
 * the in-process token_get_all syntax check) rejects them, but the fix lives in
 * src/ and is out of scope for the test layer. They are listed by name here so
 * the exemption is auditable rather than silent: when the generator stops
 * emitting these constructs, drop the entry and the spec rejoins the gate.
 *
 *  - bbc.json, here_tracking.json: a component literally named `Data` emits
 *    `final class Data extends Data`, which `use Spatie\LaravelData\Data;` turns
 *    into a self-redeclaration ("Cannot redeclare class App\\Data\\Data").
 *  - xero.json: several schemas give a `bool` property a string default
 *    ("Cannot use string as default value for parameter $hasAttachments").
 *
 * @var array<string, true>
 */
const PHP_LINT_EXEMPT_SPECS = [
    'bbc.json' => true,
    'here_tracking.json' => true,
    'xero.json' => true,
];

/**
 * Compile-level gate over generated PHP, complementing the in-process
 * token_get_all syntax check. `php -l` runs the real PHP compiler, so it
 * rejects code that tokenizes cleanly yet cannot compile, e.g. `?mixed`
 * (mixed already includes null), `bool $x = 'str'` (bad default), or a class
 * that redeclares its own import. token_get_all passes all of those, so this is
 * a genuine addition, not a duplicate of the syntax gate.
 *
 * One `php -l` invocation is one OS process and the full corpus emits tens of
 * thousands of files, so callers cap how many files per spec they hand in (the
 * conformance fixtures, which cover every construct by design, are linted in
 * full and uncapped). The files are written to a private temp dir and linted by
 * a bounded parallel pool so the gate stays well under a minute in CI.
 *
 * Returns one human-readable message per failing file (empty when all compile).
 *
 * @param  iterable<GeneratedFile>  $files
 * @param  string  $label  origin tag woven into failure messages (e.g. the spec name)
 * @param  int|null  $perFileCap  max files to lint from this set, or null for all
 * @return list<string>
 */
function phpLintFailures(iterable $files, string $label, ?int $perFileCap = null): array
{
    // Stable order so a cap selects the same files run to run (determinism).
    $ordered = [];
    foreach ($files as $file) {
        $ordered[$file->filename()] = $file->code;
    }
    ksort($ordered);
    if ($perFileCap !== null) {
        $ordered = array_slice($ordered, 0, $perFileCap, true);
    }

    if ($ordered === []) {
        return [];
    }

    $dir = sys_get_temp_dir().'/openapi-laravel-lint-'.bin2hex(random_bytes(6));
    if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        return ["could not create lint temp dir {$dir}"];
    }

    // Map each on-disk path back to its logical filename for failure reporting.
    $paths = [];
    $i = 0;
    foreach ($ordered as $filename => $code) {
        $path = $dir.'/'.$i.'.php';
        file_put_contents($path, $code);
        $paths[$path] = $filename;
        $i++;
    }

    try {
        $failures = runPhpLintPool(array_keys($paths));
    } finally {
        foreach (array_keys($paths) as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }

    $messages = [];
    foreach ($failures as $path => $output) {
        $messages[] = "{$label} :: {$paths[$path]}: ".summarizePhpLintError($output);
    }

    return $messages;
}

/**
 * Run `php -l` over a list of files with a bounded pool of concurrent worker
 * processes. `php -l` cannot batch (one file per process), so concurrency is
 * the only lever that keeps a multi-thousand-file lint sweep fast.
 *
 * Returns a map of failing-path => captured stdout+stderr (empty when clean).
 *
 * @param  list<string>  $paths
 * @return array<string, string>
 */
function runPhpLintPool(array $paths): array
{
    $workers = max(2, (int) (shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: '4'));
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

    $failures = [];
    $queue = $paths;
    /** @var array<int, array{resource, array<int, resource>, string}> $running */
    $running = [];

    while ($queue !== [] || $running !== []) {
        while (count($running) < $workers && $queue !== []) {
            $path = array_shift($queue);
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open([$php, '-l', '-d', 'display_errors=stderr', $path], $descriptors, $pipes);
            if (! is_resource($process)) {
                $failures[$path] = 'could not spawn php -l';

                continue;
            }
            $running[] = [$process, $pipes, $path];
        }

        usleep(500);

        foreach ($running as $key => [$process, $pipes, $path]) {
            $status = proc_get_status($process);
            if ($status['running']) {
                continue;
            }
            // stderr first: it carries the real diagnostic ("Fatal error: ...");
            // stdout only carries the trailing "Errors parsing <file>" line.
            $output = stream_get_contents($pipes[2]).stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if ($status['exitcode'] !== 0) {
                $failures[$path] = $output;
            }
            unset($running[$key]);
        }
    }

    return $failures;
}

/**
 * Reduce raw `php -l` output to the single most informative line: the actual
 * "Fatal error:" / "Parse error:" diagnostic, with the temp path stripped so
 * the message is stable. Falls back to the first non-empty line.
 */
function summarizePhpLintError(string $output): string
{
    $lines = preg_split('/\R/', trim($output)) ?: [];
    $pick = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($pick === '') {
            $pick = $line;
        }
        if (preg_match('/\b(Fatal error|Parse error|Compile error)\b/i', $line)) {
            $pick = $line;
            break;
        }
    }

    // Drop the absolute temp path; keep the " on line N" suffix for context.
    $pick = (string) preg_replace('# in /\S+\.php( on line \d+)#', '$1', $pick);

    return $pick !== '' ? $pick : 'php -l failed';
}
