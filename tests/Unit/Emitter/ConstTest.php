<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * `const` (JSON Schema / OpenAPI 3.1) pins a value to a single literal. The
 * generator treats it as a one-value enum: the property keeps a concrete type
 * and gains a single-value Rule::in, matching the sibling TS generator.
 *
 * @param  array<string, mixed>  $properties
 */
function generateConstHolder(array $properties): GeneratedFile
{
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => [
            'schemas' => [
                'Holder' => ['type' => 'object', 'properties' => $properties],
            ],
        ],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return (new ModelGenerator)->generate($spec)['HolderData'];
}

it('enforces a typed string const with a single-value in rule', function () {
    $code = generateConstHolder([
        'version' => ['type' => 'string', 'const' => 'v1'],
    ])->code;

    expect($code)->toContain("'version' => ['sometimes', Rule::in(['v1'])]")
        ->and($code)->toContain('public readonly ?string $version = null')
        ->and($code)->toContain('use Illuminate\Validation\Rule;');
});

it('infers the PHP type of a bare const from its literal', function () {
    $code = generateConstHolder([
        'kind' => ['const' => 'fixed'],
        'level' => ['const' => 3],
    ])->code;

    expect($code)->toContain('public readonly ?string $kind = null')
        ->and($code)->toContain("'kind' => ['sometimes', Rule::in(['fixed'])]")
        ->and($code)->toContain('public readonly ?int $level = null')
        ->and($code)->toContain("'level' => ['sometimes', Rule::in([3])]");
});

it('escapes a const literal in the in rule', function () {
    $code = generateConstHolder([
        'tag' => ['type' => 'string', 'const' => "O'Brien"],
    ])->code;

    expect($code)->toContain("Rule::in(['O\\'Brien'])");
});
