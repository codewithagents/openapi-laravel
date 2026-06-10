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
