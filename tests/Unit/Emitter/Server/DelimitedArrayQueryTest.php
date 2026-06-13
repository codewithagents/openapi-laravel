<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Unit coverage for issue #132: a non-exploded delimited array query parameter
 * (form + explode: false, spaceDelimited, pipeDelimited) is no longer skipped.
 * The generated fromQuery() factory splits the single joined string on the
 * declared delimiter BEFORE the array rules validate, so the items participate
 * in validation. The exploded (repeated-key) form and non-array parameters stay
 * unchanged.
 *
 * @return array<string, string> query class name -> generated code
 */
function delimitedQueryFiles(): array
{
    $doc = (new SpecParser)->parseFileToDocument(__DIR__.'/../../../Fixtures/server/query-parameters.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($doc);

    $out = [];
    foreach ($generator->queryFiles() as $name => $file) {
        $out[$name] = $file->code;
    }

    return $out;
}

it('splits a pipeDelimited array on "|" and a form+explode:false array on ","', function () {
    $code = delimitedQueryFiles()['SearchWidgetsQueryData'];

    // matrix is pipeDelimited, csv is form + explode: false, tags is spaceDelimited.
    expect($code)->toContain("foreach (['matrix' => '|', 'csv' => ',', 'tags' => ' '] as \$name => \$delimiter) {")
        ->and($code)->toContain('$query[$name] = explode($delimiter, $query[$name]);')
        // The single present, string value is split before validation; an absent
        // key stays absent and a non-string value is left untouched.
        ->and($code)->toContain('if (array_key_exists($name, $query) && is_string($query[$name])) {');
});

it('runs the per-item array rules against the split delimited arrays', function () {
    $code = delimitedQueryFiles()['SearchWidgetsQueryData'];

    // The split feeds the EXACT array element rules the pipeline produces, so
    // the items are validated like any other array query parameter.
    expect($code)->toContain("'matrix' => ['sometimes', 'array']")
        ->and($code)->toContain("'matrix.*' => ['string']")
        ->and($code)->toContain("'csv' => ['sometimes', 'array']")
        ->and($code)->toContain("'csv.*' => ['string']")
        // tags carries minItems + item minLength, both enforced on the split items.
        ->and($code)->toContain("'tags' => ['sometimes', 'array', 'min:1']")
        ->and($code)->toContain("'tags.*' => ['string', 'min:2']");
});

it('splits BEFORE the boolean literal mapping when an operation has both', function () {
    $code = delimitedQueryFiles()['FilterWidgetsQueryData'];

    // The delimiter split runs first, then the boolean true/false -> 1/0 mapping,
    // both inside the same fromQuery() factory.
    $splitAt = strpos($code, '$query[$name] = explode($delimiter, $query[$name]);');
    $boolAt = strpos($code, "'true' => '1',");

    expect($splitAt)->not->toBeFalse()
        ->and($boolAt)->not->toBeFalse()
        ->and($splitAt)->toBeLessThan($boolAt)
        ->and($code)->toContain("foreach (['kinds' => ','] as \$name => \$delimiter) {")
        ->and($code)->toContain("foreach (['active'] as \$name) {");
});

it('leaves an exploded (repeated-key) array unchanged: no split, plain factory', function () {
    // ListWidgets declares `ids` as a plain array (default form + explode: true),
    // so it stays the repeated ?ids[]= form with NO delimiter split.
    $code = delimitedQueryFiles()['ListWidgetsQueryData'];

    expect($code)->toContain('return self::validateAndCreate($request->query->all());')
        ->and($code)->not->toContain('explode($delimiter')
        ->and($code)->not->toContain('$name => $delimiter');
});

it('does not split a non-array delimited parameter', function () {
    // CreateWidget's only special parameter is the boolean validateOnly: a scalar
    // gets the boolean mapping but never the array split, even with a style set.
    $code = delimitedQueryFiles()['CreateWidgetQueryData'];

    expect($code)->not->toContain('explode($delimiter')
        ->and($code)->toContain("foreach (['validateOnly'] as \$name) {");
});
