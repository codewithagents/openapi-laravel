<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Console\OptionException;
use CodeWithAgents\OpenApiLaravel\Console\OptionValidator;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The OpenAPI spec is untrusted input. The generator writes PHP source that the
 * host then loads and executes, so any spec-derived value that reaches a class
 * name, a docblock, a string literal, or a validation rule is an injection
 * surface. This fixture is deliberately hostile: a schema name carrying quotes,
 * backslashes and `*\/`; an enum value carrying quotes and backslashes; an
 * operation summary and a path carrying `*\/ } system('x'); /*`; and a
 * `pattern` containing both PCRE delimiters `#` and `~`. We then prove every
 * generated file still parses as PHP, the injection is neutralized, the regex
 * rule is not silently dropped, and the namespace validator rejects bad input.
 */

/**
 * @return array<string, string> filename => generated PHP source
 */
function generateHostileFiles(): array
{
    $spec = <<<'JSON'
    {
      "openapi": "3.0.3",
      "info": { "title": "Hostile", "version": "1.0.0" },
      "paths": {
        "/danger": {
          "get": {
            "tags": ["danger"],
            "operationId": "doDanger",
            "summary": "x */ } system('x'); /* and a \\ backslash and a ' quote",
            "responses": {
              "200": {
                "description": "ok",
                "content": {
                  "application/json": {
                    "schema": { "$ref": "#/components/schemas/Evil_Name__End_Comment" }
                  }
                }
              }
            }
          }
        },
        "/p */ } system('x'); /*": {
          "get": {
            "tags": ["danger"],
            "operationId": "doPathDanger",
            "responses": {
              "200": { "description": "ok" }
            }
          }
        }
      },
      "components": {
        "schemas": {
          "Evil_Name__End_Comment": {
            "type": "object",
            "required": ["code"],
            "properties": {
              "code": {
                "type": "string",
                "pattern": "#-foo-~-bar-#-baz-~"
              },
              "mood": {
                "type": "string",
                "enum": ["it's \\ wild"]
              }
            }
          }
        }
      }
    }
    JSON;

    $path = sys_get_temp_dir().'/openapi-laravel-hostile-'.uniqid().'.json';
    file_put_contents($path, $spec);

    $document = (new SpecParser)->parseFile($path);
    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($document);

    $options = new ServerOptions;
    $descriptors = (new OperationCollector($options, $generator->registry()))->collect($document);
    $controllerFiles = (new ControllerGenerator($options))->generate($descriptors);
    $routeFile = (new RouteGenerator($options))->generate($descriptors);

    $files = [];
    foreach ($modelFiles as $file) {
        $files[$file->filename()] = $file->code;
    }
    foreach ($controllerFiles as $file) {
        $files[$file->filename()] = $file->code;
    }
    $files[$routeFile->filename()] = $routeFile->code;

    @unlink($path);

    return $files;
}

it('emits syntactically valid PHP for every file built from a hostile spec', function () {
    foreach (generateHostileFiles() as $name => $code) {
        expect(fn () => token_get_all($code, TOKEN_PARSE))
            ->not->toThrow(Throwable::class, "ParseError in {$name}");
    }
});

it('never lets an injected */ survive unneutralized inside a docblock', function () {
    foreach (generateHostileFiles() as $name => $code) {
        // Walk each docblock and assert no raw `*/` appears before its proper
        // terminator. A neutralized value renders as `* /`, which is harmless.
        if (preg_match_all('#/\*\*(.*?)\*/#s', $code, $matches) !== false) {
            foreach ($matches[1] as $inner) {
                expect(str_contains($inner, '*/'))
                    ->toBeFalse("Unneutralized */ inside a docblock in {$name}");
            }
        }
    }
});

it('keeps the injected payload inert inside the docblock, never as a PHP statement', function () {
    $files = generateHostileFiles();
    $controller = $files['AbstractDangerController.php'] ?? '';

    // The summary and path both carry the `*/ } system('x'); /*` payload. The
    // `*/` is neutralized to `* /` so the comment never closes early, which
    // means the `system('x')` text stays inside the comment as inert prose
    // rather than becoming an executable statement. token_get_all already
    // proves the file parses; here we tokenize and assert the payload only ever
    // lives inside T_DOC_COMMENT / T_COMMENT tokens, never as live code.
    expect($controller)->toContain('* /');

    foreach (token_get_all($controller, TOKEN_PARSE) as $token) {
        if (is_array($token) && str_contains((string) $token[1], "system('x')")) {
            expect($token[0])->toBeIn([T_DOC_COMMENT, T_COMMENT]);
        }
    }

    // The payload must never appear as a bare function-call statement, i.e.
    // outside any comment token.
    $codeOutsideComments = '';
    foreach (token_get_all($controller, TOKEN_PARSE) as $token) {
        if (is_array($token) && in_array($token[0], [T_DOC_COMMENT, T_COMMENT], true)) {
            continue;
        }
        $codeOutsideComments .= is_array($token) ? $token[1] : $token;
    }

    expect($codeOutsideComments)->not->toContain('system');
});

it('emits the regex rule for a pattern containing both # and ~ instead of dropping it', function () {
    $files = generateHostileFiles();
    // The schema is solely owned by the 'danger' tag, so the grouped layout
    // (issue #93, the only layout) places it in the Danger/ subdirectory.
    $model = $files['Danger/EvilNameEndCommentData.php'] ?? '';

    // C-4: the field must carry a regex rule, never silently under-validated.
    expect($model)->toContain("'code' => [")
        ->and($model)->toContain('regex:');

    // The pattern body survives in some delimiter, with no PHP-breaking content.
    expect($model)->toContain('-foo-')
        ->and($model)->toContain('-bar-')
        ->and($model)->toContain('-baz-');
});

it('rejects an illegal --namespace before any file is written', function () {
    expect(fn () => OptionValidator::namespace('--namespace', 'App\\Data; evil()'))
        ->toThrow(OptionException::class);

    expect(fn () => OptionValidator::namespace('--namespace', 'App Data'))
        ->toThrow(OptionException::class);

    expect(fn () => OptionValidator::namespace('--namespace', "App\\'); system('x'); //"))
        ->toThrow(OptionException::class);

    // A legitimate namespace passes through untouched.
    expect(OptionValidator::namespace('--namespace', 'Acme\\Generated\\Data'))
        ->toBe('Acme\\Generated\\Data');
});

it('rejects an illegal --suffix and accepts a legal one', function () {
    expect(fn () => OptionValidator::identifier('--suffix', "Data'; system('x'); //"))
        ->toThrow(OptionException::class);

    expect(OptionValidator::identifier('--suffix', 'Dto'))->toBe('Dto');
    expect(OptionValidator::identifier('--suffix', '', allowEmpty: true))->toBe('');
});
