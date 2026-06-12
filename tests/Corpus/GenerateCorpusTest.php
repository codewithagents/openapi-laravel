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
    $document = (new SpecParser)->parseFileToDocument($path);
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

/**
 * Corpus guard for the oneOf/anyOf union-typing feature on real input:
 * ably_control.json declares an `authentication` property as
 * `oneOf: [AwsAccessKeys, AwsAssumeRole]`, an undiscriminated object union. The
 * interim fix for issue #31 types such a property `mixed` (presence-only, so a
 * valid non-first variant is not false-rejected by laravel-data's nested-rule
 * inference) while preserving the variant union in the `@var` docblock for
 * IDE/PHPStan. This is the analogue of the non-empty guards the allOf and
 * additionalProperties features carry: proof the feature fires on real input,
 * not just hand-built fixtures.
 */
it('types a curated corpus object-union oneOf as mixed with a variant docblock (issue #31, ably_control)', function () {
    $path = __DIR__.'/../Fixtures/specs/ably_control.json';
    $document = (new SpecParser)->parseFileToDocument($path);
    $files = (new ModelGenerator)->generate($document);

    expect($files)->toHaveKey('AwsLambdaRulePostTargetData');

    $code = $files['AwsLambdaRulePostTargetData']->code;

    // The variant union is preserved in the docblock, but the property declares
    // as `mixed` so laravel-data does not infer nested rules and false-reject a
    // valid non-first variant.
    expect($code)->toContain('/** @var AwsAccessKeysData|AwsAssumeRoleData')
        ->and($code)->toContain('public readonly mixed $authentication')
        ->and($code)->not->toContain('public readonly AwsAccessKeysData|AwsAssumeRoleData');
});
