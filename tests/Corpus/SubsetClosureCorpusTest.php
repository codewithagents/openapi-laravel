<?php

declare(strict_types=1);

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\SchemaClosure;
use CodeWithAgents\OpenApiLaravel\Emitter\SubsetSelection;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Subset generation proof on real specs (issue #44): selecting a single
 * component schema from a large spec generates ONLY its transitive closure, the
 * slice is a strict subset of the full output, and the slice has no dangling
 * reference (every signature type resolves within the emitted set). This is the
 * subset analogue of the full-corpus import-resolution gate.
 *
 * @param  array{spec: string, schema: string}  $case
 */
it('generates a self-consistent, dangling-free slice from a real spec', function (array $case) {
    $path = __DIR__.'/../Fixtures/specs/'.$case['spec'];
    $document = (new SpecParser)->parseFile($path);

    $closure = (new SchemaClosure)->resolve($document, SubsetSelection::of([], [$case['schema']]));
    expect($closure->hasUnknown())->toBeFalse();

    // The slice must close over more than the seed (these schemas have deps), and
    // it must be a STRICT subset of the full component set.
    $fullCount = count((array) $document->components->schemas);
    expect(count($closure->schemas))->toBeGreaterThan(1)
        ->and(count($closure->schemas))->toBeLessThan($fullCount);

    $files = (new ModelGenerator(new GeneratorOptions(keepSchemas: $closure->schemaSet())))->generate($document);
    expect($files)->not->toBe([]);

    // No dangling reference: every class short-name used in a signature resolves
    // to an import, another emitted class, or a builtin.
    $defined = definedClassNames($files);
    foreach ($files as $file) {
        $unresolved = unresolvedSignatureTypes($file->code, $defined);
        expect($unresolved)->toBe(
            [],
            'Dangling reference(s) ['.implode(', ', $unresolved)."] in {$file->filename()} (subset of {$case['spec']})"
        );

        // And it must compile.
        token_get_all($file->code, TOKEN_PARSE);
    }
})->with([
    'github issue closure' => [['spec' => 'github.json', 'schema' => 'issue']],
    'github pull-request closure' => [['spec' => 'github.json', 'schema' => 'pull-request']],
    'stripe charge closure' => [['spec' => 'stripe.json', 'schema' => 'charge']],
]);

/**
 * Exercise the closure's full traversal over every real corpus spec, selecting
 * the first tag in the spec. Real specs carry every shape the walk must tolerate
 * (operations without responses, parameters without schemas, mapping-pointer
 * discriminators, non-JSON content), so this drives the defensive branches of
 * SchemaClosure with real input. The assertion is a robustness one: the closure
 * resolves without throwing, is a subset of the full schema set, and a generated
 * slice has no dangling reference.
 */
it('resolves a tag-scoped closure over every corpus spec without dangling refs', function (string $path) {
    $document = (new SpecParser)->parseFile($path);

    // Pick the first tag carried by any operation, deterministically.
    $tag = firstOperationTag($document);
    if ($tag === null) {
        expect(true)->toBeTrue(); // no tagged operation: nothing to subset by.

        return;
    }

    $closure = (new SchemaClosure)->resolve($document, SubsetSelection::of([$tag], []));
    expect($closure->hasUnknown())->toBeFalse();

    $files = (new ModelGenerator(new GeneratorOptions(keepSchemas: $closure->schemaSet())))->generate($document);

    $defined = definedClassNames($files);
    foreach ($files as $file) {
        $unresolved = unresolvedSignatureTypes($file->code, $defined);
        expect($unresolved)->toBe(
            [],
            "Dangling reference(s) in {$file->filename()} (tag '{$tag}' subset of ".basename($path).')'
        );
    }
})->with('corpus_specs');

/**
 * The first tag carried by any operation in a document, scanning paths and
 * methods in a stable order, or null when no operation is tagged.
 */
function firstOperationTag(OpenApi $document): ?string
{
    $paths = $document->paths;
    if ($paths === null) {
        return null;
    }

    $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
    $rows = [];
    foreach ($paths->getPaths() as $path => $pathItem) {
        if (! $pathItem instanceof PathItem) {
            continue;
        }
        $rows[(string) $path] = $pathItem;
    }
    ksort($rows);

    foreach ($rows as $pathItem) {
        foreach ($methods as $method) {
            $operation = $pathItem->{$method} ?? null;
            if (! $operation instanceof Operation || ! is_array($operation->tags)) {
                continue;
            }
            foreach ($operation->tags as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    return trim($tag);
                }
            }
        }
    }

    return null;
}
