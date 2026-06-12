<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Regression guards for nullable enums. These pass today; the tests pin the
 * behaviour so a `null` enum member is never emitted as a case and the property
 * stays nullable.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateEnumSchemas(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return (new ModelGenerator)->generate($spec);
}

it('keeps a 3.0 nullable:true enum property nullable without a null case', function () {
    $files = generateEnumSchemas([
        'Status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'allOf' => [['$ref' => '#/components/schemas/Status']],
                    'nullable' => true,
                ],
            ],
        ],
    ]);

    $enum = $files['Status']->code;
    $holder = $files['HolderData']->code;

    // The enum has only the two real cases, no null case.
    expect($enum)->toContain("case Active = 'active';")
        ->and($enum)->toContain("case Inactive = 'inactive';")
        ->and($enum)->not->toContain('null');

    // The property resolves to the enum type and stays nullable.
    expect($holder)->toContain('public readonly ?Status $status = null');
});

it('drops a null member from an inline enum and keeps the property nullable', function () {
    $files = generateEnumSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => ['string', 'null'], 'enum' => ['a', 'b', null]],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // null is not an enum value: the in rule lists only the two real members.
    expect($code)->toContain("Rule::in(['a', 'b'])")
        ->and($code)->toContain('public readonly ?string $mode = null')
        ->and($code)->toContain("'mode' => ['sometimes', 'nullable',");
});
