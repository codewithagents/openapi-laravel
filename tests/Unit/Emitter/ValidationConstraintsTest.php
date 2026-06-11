<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Regression tests for the batch of silent-validation fixes: exclusive numeric
 * bounds, float enums, multi-type arrays, multipleOf, uniqueItems, defaults,
 * date vs date-time formats, and the never-`?mixed` rule. Each test pins a
 * specific generated rule or declaration so a regression cannot return silently.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateConstraintSchemas(array $schemas, string $openapi = '3.0.3'): array
{
    $document = [
        'openapi' => $openapi,
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return (new ModelGenerator)->generate($spec);
}

// FIX 1: exclusiveMinimum / exclusiveMaximum, both spec forms.

it('emits gt/lt for the OpenAPI 3.0 boolean exclusive form', function () {
    $files = generateConstraintSchemas([
        'Range' => [
            'type' => 'object',
            'properties' => [
                'n' => ['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => true, 'maximum' => 10, 'exclusiveMaximum' => true],
            ],
        ],
    ]);

    $code = $files['RangeData']->code;

    expect($code)->toContain("'n' => ['sometimes', 'integer', 'gt:1', 'lt:10'],")
        ->and($code)->not->toContain("'min:1'")
        ->and($code)->not->toContain("'max:10'");
});

it('emits gt/lt for the OpenAPI 3.1 numeric exclusive form', function () {
    $files = generateConstraintSchemas([
        'Range' => [
            'type' => 'object',
            'properties' => [
                'n' => ['type' => 'number', 'exclusiveMinimum' => 1.5, 'exclusiveMaximum' => 9.5],
            ],
        ],
    ], '3.1.0');

    $code = $files['RangeData']->code;

    expect($code)->toContain("'gt:1.5'")
        ->and($code)->toContain("'lt:9.5'")
        ->and($code)->toContain("'numeric'");
});

it('keeps inclusive minimum/maximum as min/max', function () {
    $files = generateConstraintSchemas([
        'Range' => [
            'type' => 'object',
            'properties' => [
                'n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
        ],
    ]);

    expect($files['RangeData']->code)
        ->toContain("'n' => ['sometimes', 'integer', 'min:1', 'max:10'],");
});

it('treats explicit exclusiveMinimum:false as inclusive (min, not gt)', function () {
    $files = generateConstraintSchemas([
        'Range' => [
            'type' => 'object',
            'properties' => [
                'n' => ['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => false],
            ],
        ],
    ]);

    expect($files['RangeData']->code)->toContain("'min:1'")
        ->and($files['RangeData']->code)->not->toContain("'gt:1'");
});

// FIX 2: float/number enums.

it('emits Rule::in for a float-valued enum property', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'ratio' => ['type' => 'number', 'enum' => [1.5, 2.5]],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain('Rule::in([1.5, 2.5])')
        ->toContain('public readonly ?float $ratio');
});

it('emits a non-empty valid Data class for a top-level float-enum component', function () {
    $files = generateConstraintSchemas([
        'Ratio' => ['type' => 'number', 'enum' => [1.5, 2.5]],
    ]);

    // A float enum cannot be a PHP backed enum: it must be a Data class that
    // carries the constraint, not an empty class.
    expect($files)->toHaveKey('RatioData')
        ->and($files)->not->toHaveKey('Ratio');

    $code = $files['RatioData']->code;

    expect($code)->toContain('public readonly float $value')
        ->and($code)->toContain('Rule::in([1.5, 2.5])')
        // Not an empty class body.
        ->and($code)->toContain('public function __construct');

    // The generated class is valid PHP.
    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

// FIX (#bool-enum): a boolean member of a mixed-type enum survives into Rule::in.

it('keeps a boolean member of a mixed-type enum in Rule::in', function () {
    $files = generateConstraintSchemas([
        // A heterogeneous enum mixing a string, int, bool, and float. The bool
        // member used to be dropped (enumValues kept only string|int|float), so a
        // spec-valid `true` was false-rejected. It must now be a `true` literal.
        'Choice' => ['enum' => ['one', 2, true, 3.5]],
    ]);

    expect($files)->toHaveKey('ChoiceData');
    $code = $files['ChoiceData']->code;

    expect($code)->toContain("Rule::in(['one', 2, true, 3.5])")
        // A bool/float-bearing enum is NOT a native backed enum: it is a Data
        // class carrying the membership rule.
        ->and($code)->toContain('public readonly')
        ->and($code)->toContain('Rule::in');

    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

it('emits true/false literals for a pure boolean enum, not a backed enum', function () {
    $files = generateConstraintSchemas([
        'Flag' => ['enum' => [true, false]],
    ]);

    expect($files)->toHaveKey('FlagData');
    $code = $files['FlagData']->code;

    // A boolean cannot back a native PHP enum, so this is a Data class with a
    // Rule::in carrying the boolean literals.
    expect($code)->toContain('Rule::in([true, false])')
        ->and($code)->not->toContain('enum FlagData');

    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

// FIX 3: multi-type arrays.

it('emits a string|int union for a two-member type array', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'integer']],
            ],
        ],
    ], '3.1.0');

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly string|int|null $value = null')
        ->and($code)->toContain('/** @var string|int */')
        // Presence-only rules: no 'string' type rule that would reject an int.
        ->and($code)->toContain("'value' => ['sometimes'],");
});

it('keeps ["integer","null"] as a nullable int, not a union', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['integer', 'null']],
            ],
        ],
    ], '3.1.0');

    expect($files['HolderData']->code)
        ->toContain('public readonly ?int $value = null')
        // A nullable scalar, not a union: no `int|` member and no union docblock.
        ->not->toContain('int|')
        ->not->toContain('/** @var int|');
});

it('keeps ["string","null"] as a nullable string, not a union', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'null']],
            ],
        ],
    ], '3.1.0');

    expect($files['HolderData']->code)
        ->toContain('public readonly ?string $value = null')
        // A nullable scalar, not a union: no `string|` union member.
        ->not->toContain('public readonly string|')
        ->not->toContain('/** @var string|');
});

// FIX 4: multipleOf.

it('emits a MultipleOfRule for a numeric multipleOf', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'n' => ['type' => 'integer', 'multipleOf' => 3],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('new MultipleOfRule(3)')
        ->and($code)->toContain('use CodeWithAgents\OpenApiLaravel\Support\MultipleOfRule;');
});

// FIX 5: uniqueItems.

it('adds distinct to the field.* item rules for uniqueItems', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string']],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'tags.*' => ['string', 'distinct'],");
});

it('adds distinct even when array items carry no rules', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'ids' => ['type' => 'array', 'uniqueItems' => true],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain("'ids.*' => ['distinct'],");
});

// FIX 6: default values.

it('seeds an optional scalar default into the constructor and stays non-null', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'default' => 10],
                'label' => ['type' => 'string', 'default' => 'hi'],
                'active' => ['type' => 'boolean', 'default' => true],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly int $limit = 10')
        ->and($code)->toContain("public readonly string \$label = 'hi'")
        ->and($code)->toContain('public readonly bool $active = true');
});

it('treats a required property with a default as sometimes, seeding the default', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'required' => ['limit'],
            'properties' => [
                'limit' => ['type' => 'integer', 'default' => 5],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // A default fills an omitted value, so input is `sometimes`, not `required`.
    expect($code)->toContain("'limit' => ['sometimes', 'integer'],")
        ->and($code)->not->toContain("'limit' => ['required'")
        ->and($code)->toContain('public readonly int $limit = 5');
});

it('handles a default on an enum-typed property without breaking the type', function () {
    $files = generateConstraintSchemas([
        'Status' => ['type' => 'string', 'enum' => ['on', 'off']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'status' => ['$ref' => '#/components/schemas/Status', 'default' => 'on'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // The enum-typed property keeps its enum type and a null default (a raw
    // scalar literal cannot type as the enum), and stays valid PHP.
    expect($code)->toContain('$status = null')
        ->and($code)->not->toContain("= 'on'");
    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

it('keeps a nullable property with a default nullable but seeded', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'n' => ['type' => 'integer', 'nullable' => true, 'default' => 7],
            ],
        ],
    ]);

    expect($files['HolderData']->code)->toContain('public readonly ?int $n = 7');
});

// FIX 6b (#22): a default whose PHP type does not match the property's declared
// scalar type must NOT be seeded. Specs routinely carry a mistyped default (xero
// gives a `bool` property the string "false"); emitting it verbatim produces
// `bool $x = 'false'`, a fatal "Cannot use string as default value". The guard
// drops the bad default and falls back to the `= null`/optional behaviour.

it('does not seed a string default on a bool property and stays compilable', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                // The xero shape: a boolean property with the string default "false".
                'hasAttachments' => ['type' => 'boolean', 'default' => 'false'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly ?bool $hasAttachments = null,')
        ->and($code)->not->toContain("= 'false'")
        ->and(phpLintFailures($files, 'mistyped-bool-default'))->toBe([]);
});

it('does not seed a non-numeric string default on an int property', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'default' => 'lots'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly ?int $limit = null,')
        ->and($code)->not->toContain("= 'lots'")
        ->and(phpLintFailures($files, 'mistyped-int-default'))->toBe([]);
});

it('does not seed a string default on a numeric property and stays compilable', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'ratio' => ['type' => 'number', 'default' => '1.5'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly ?float $ratio = null,')
        ->and($code)->not->toContain("= '1.5'")
        ->and(phpLintFailures($files, 'mistyped-number-default'))->toBe([]);
});

it('still seeds correctly-typed scalar defaults (bool, int, float, string)', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'active' => ['type' => 'boolean', 'default' => true],
                'limit' => ['type' => 'integer', 'default' => 10],
                'ratio' => ['type' => 'number', 'default' => 1.5],
                'label' => ['type' => 'string', 'default' => 'hi'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly bool $active = true')
        ->and($code)->toContain('public readonly int $limit = 10')
        ->and($code)->toContain('public readonly float $ratio = 1.5')
        ->and($code)->toContain("public readonly string \$label = 'hi'")
        ->and(phpLintFailures($files, 'correct-defaults'))->toBe([]);
});

// FIX 7: date vs date-time.

it('emits date_format:Y-m-d for format date', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'd' => ['type' => 'string', 'format' => 'date'],
            ],
        ],
    ]);

    expect($files['HolderData']->code)
        ->toContain("'d' => ['sometimes', 'string', 'date_format:Y-m-d'],");
});

it('emits the RFC3339 rule for format date-time', function () {
    $files = generateConstraintSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'ts' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain("'ts' => ['sometimes', 'string', new Rfc3339DateTimeRule],")
        ->and($code)->toContain('use CodeWithAgents\OpenApiLaravel\Support\Rfc3339DateTimeRule;');
});

// FIX 8: never ?mixed.

it('never emits ?mixed for a nullable mixed-fallback property', function () {
    $files = generateConstraintSchemas([
        'Thing' => [
            'type' => 'object',
            'required' => ['value'],
            'properties' => [
                'value' => [
                    'oneOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]],
                    'nullable' => true,
                ],
            ],
        ],
    ]);

    $code = $files['ThingData']->code;

    expect($code)->toContain('mixed $value')
        ->and($code)->not->toContain('?mixed');

    // token_get_all does NOT reject ?mixed, so lint the file with php -l.
    $dir = sys_get_temp_dir().'/oal_mixed_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir.'/'.$files['ThingData']->filename();
    file_put_contents($path, $code);

    $output = (string) shell_exec('php -l '.escapeshellarg($path).' 2>&1');

    expect($output)->toContain('No syntax errors detected');
});
