<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Backed-enum backing-type inference (issue #145). A component enum is int-backed
 * only when every value round-trips through int unchanged; a non-canonical
 * decimal string (`"01"`, `"040000"`) would otherwise be corrupted to its int
 * form (`"01"` emitted as `1`), breaking the wire round-trip and, when a sibling
 * `"1"` is present, producing a fatal "Duplicate value in enum" PHP error.
 *
 * @param  array<string, mixed>  $enum
 * @return array<string, GeneratedFile>
 */
function generateBackedEnum(array $enum): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => ['Code' => ['type' => 'string', 'enum' => $enum]]],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return (new ModelGenerator)->generate($spec);
}

it('int-backs an enum whose every value is a canonical int string', function () {
    $code = generateBackedEnum(['0', '1', '42'])['Code']->code;

    expect($code)->toContain('enum Code: int')
        ->and($code)->toContain('case Value0 = 0;')
        ->and($code)->toContain('case Value1 = 1;')
        ->and($code)->toContain('case Value42 = 42;');
});

it('string-backs an enum with a leading-zero value instead of corrupting it to an int (#145)', function () {
    // "01" is not the canonical decimal form of 1: int-backing would emit
    // `case ... = 1`, silently rewriting the wire value "01" to 1.
    $code = generateBackedEnum(['01', '02', '03'])['Code']->code;

    expect($code)->toContain('enum Code: string')
        ->and($code)->toContain("'01'")
        ->and($code)->toContain("'02'")
        ->and($code)->toContain("'03'")
        // No int corruption: the bare-int literal `= 1;` must never appear.
        ->and($code)->not->toContain('= 1;')
        ->and($code)->not->toContain('enum Code: int');
});

it('string-backs (not duplicate-int) an enum mixing "01" and "1" so it never fatals (#145)', function () {
    // Under the old all-digits rule both "01" and "1" int-backed to 1, emitting
    // two `case ... = 1;` lines: a fatal "Duplicate value in enum". String
    // backing keeps them distinct.
    $code = generateBackedEnum(['1', '01'])['Code']->code;

    expect($code)->toContain('enum Code: string')
        ->and($code)->toContain("'1'")
        ->and($code)->toContain("'01'")
        // The two cases carry distinct string literals, not a duplicated int 1.
        ->and(substr_count($code, '= 1;'))->toBe(0);
});

it('string-backs a multi-digit leading-zero value like a git file mode (#145)', function () {
    $code = generateBackedEnum(['100644', '040000', '120000'])['Code']->code;

    expect($code)->toContain('enum Code: string')
        ->and($code)->toContain("'040000'")
        // "040000" must not collapse to its int form 40000.
        ->and($code)->not->toContain('= 40000;');
});

it('keeps a signed-int string string-backed, unchanged from prior behaviour (#145)', function () {
    // The unsigned-digit gate is retained, so a "-1" member stays string-backed
    // exactly as before: no widening of int-backing to signed forms.
    $code = generateBackedEnum(['0', '1', '-1'])['Code']->code;

    expect($code)->toContain('enum Code: string')
        ->and($code)->toContain("'-1'");
});

it('folds a non-ASCII enum value to its ASCII equivalent instead of dropping the accented letters', function () {
    $code = generateBackedEnum(['Straße', 'Müller', 'café'])['Code']->code;

    expect($code)->toContain('enum Code: string')
        ->and($code)->toContain('case Strasse = ')
        ->and($code)->toContain('case Muller = ')
        ->and($code)->toContain('case Cafe = ');
});
