<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The generator gate: every emitted file for every real-world corpus spec must
 * be syntactically valid PHP. token_get_all(..., TOKEN_PARSE) performs a full
 * parse in-process and throws ParseError on invalid syntax, so this is a fast
 * equivalent of running `php -l` on each generated file.
 */
it('generates syntactically valid PHP for every corpus spec', function (string $path) {
    $document = (new SpecParser)->parseFile($path);
    $files = (new ModelGenerator)->generate($document);

    foreach ($files as $file) {
        try {
            token_get_all($file->code, TOKEN_PARSE);
        } catch (Throwable $e) {
            $this->fail(
                "Invalid PHP in {$file->filename()} (from ".basename($path)."): {$e->getMessage()}\n\n".$file->code
            );
        }
    }

    expect(true)->toBeTrue();
})->with('corpus_specs');
