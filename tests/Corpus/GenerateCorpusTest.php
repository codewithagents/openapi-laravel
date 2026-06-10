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

    // Import-resolution gate: every class short-name a Data class constructor
    // references (a nested Data class, an enum, a framework type) must resolve
    // to an import, another generated class, or an allowlisted builtin. This
    // catches a syntactically-valid-but-unresolvable reference that TOKEN_PARSE
    // alone would pass.
    $defined = definedClassNames($files);
    foreach ($files as $file) {
        $unresolved = unresolvedSignatureTypes($file->code, $defined);
        if ($unresolved !== []) {
            $this->fail(
                'Unresolved class reference(s) ['.implode(', ', $unresolved)."] in {$file->filename()} ".
                '(from '.basename($path)."): used in a signature without an import or definition.\n\n".$file->code
            );
        }
    }

    expect(true)->toBeTrue();
})->with('corpus_specs');
