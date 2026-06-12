<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * A non-standard per-property `required: true` key (issue #34) is ignored by
 * OpenAPI 3.x, which only honours the schema-level `required: [...]` array. The
 * generator keeps that correct behavior (the field stays optional) and now also
 * surfaces a diagnostic so the silent information loss is reported.
 *
 * Build a minimal document from a components.schemas map and run the generator,
 * returning it so the test can read both the generated files and the warnings.
 *
 * @param  array<string, mixed>  $schemas
 */
function generateWithGenerator(array $schemas): ModelGenerator
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);

    return $generator;
}

it('warns on a non-standard per-property required key but keeps the field optional', function () {
    $generator = generateWithGenerator([
        'User' => [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'required' => true],
            ],
        ],
    ]);

    // The warning names the property and the schema.
    expect($generator->warnings())->toBe([
        'Property "email" on schema "User" has a non-standard per-property "required" key, which OpenAPI ignores. '
        .'Use the schema-level "required" array instead. This field is generated as optional.',
    ]);

    // Behavior is unchanged: the field is still optional in both the constructor
    // and the rules() output (no schema-level required array lists it).
    $code = $generator->generate(
        (new OpenApiReader)->read([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Test', 'version' => '1.0.0'],
            'paths' => new stdClass,
            'components' => ['schemas' => [
                'User' => [
                    'type' => 'object',
                    'properties' => ['email' => ['type' => 'string', 'required' => true]],
                ],
            ]],
        ])
    )['UserData']->code;

    expect($code)->toContain('public readonly ?string $email = null')
        ->and($code)->toContain("'email' => ['sometimes', 'string']")
        ->and($code)->not->toContain("'email' => ['required'");
});

it('does not warn for a normal schema-level required array and keeps the field required', function () {
    $generator = generateWithGenerator([
        'User' => [
            'type' => 'object',
            'required' => ['email'],
            'properties' => [
                'email' => ['type' => 'string'],
            ],
        ],
    ]);

    expect($generator->warnings())->toBe([]);

    $code = $generator->generate(
        (new OpenApiReader)->read([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Test', 'version' => '1.0.0'],
            'paths' => new stdClass,
            'components' => ['schemas' => [
                'User' => [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string']],
                ],
            ]],
        ])
    )['UserData']->code;

    // The schema-level required array still drives a required, non-null field.
    expect($code)->toContain('public readonly string $email,')
        ->and($code)->toContain("'email' => ['required', 'string']");
});

it('records the per-property required warning only once per property', function () {
    $generator = generateWithGenerator([
        'User' => [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'required' => true],
            ],
        ],
    ]);

    expect($generator->warnings())->toHaveCount(1);
});
