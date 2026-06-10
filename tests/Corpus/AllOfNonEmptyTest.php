<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Regression guard for the allOf merge. The generate gate only checks that every
 * emitted file parses, so an empty class (all properties dropped) would slip
 * through. These curated classes compose via `allOf` in real corpus specs and
 * were emitted empty before merging landed. Asserting they carry constructor
 * parameters proves the member properties are still merged in, not lost.
 *
 * @return array<string, list<array{0: string, 1: list<string>}>>
 */
function allOfNonEmptyExpectations(): array
{
    return [
        // spec => [class => [property names that must appear]]
        'box.json' => [
            ['CommentData', ['$id', '$message']],
            ['CollectionData', ['$id', '$name']],
        ],
        'asana.json' => [
            ['AttachmentResponseData', ['$createdAt', '$downloadUrl']],
            ['CustomFieldCompactData', ['$gid', '$resourceType']],
        ],
    ];
}

it('keeps allOf-composed corpus classes non-empty', function (string $spec, array $cases) {
    $document = (new SpecParser)->parseFile(__DIR__.'/../Fixtures/specs/'.$spec);
    $files = (new ModelGenerator)->generate($document);

    foreach ($cases as [$class, $properties]) {
        expect(array_keys($files))->toContain($class);

        $code = $files[$class]->code;

        // A merged class must have a constructor, never the empty "{ }" body.
        expect($code)->toContain('public function __construct(');

        foreach ($properties as $property) {
            expect($code)->toContain($property);
        }
    }
})->with(array_map(
    fn (string $spec, array $cases): array => [$spec, $cases],
    array_keys(allOfNonEmptyExpectations()),
    array_values(allOfNonEmptyExpectations()),
));
