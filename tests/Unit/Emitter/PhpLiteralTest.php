<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\PhpLiteral;

/**
 * PhpLiteral::numberLiteral renders a spec-derived number for embedding in a
 * generated rule string or PHP default. A small- or large-magnitude float
 * stringifies in scientific notation by default (`(string) 1e-7` is `"1.0E-7"`),
 * which breaks a Laravel rule-string parameter and is opaque in a default; it
 * must come out as plain fixed-decimal instead (issue #148). Every rendered
 * value must still parse back to the original float.
 */
it('renders an integer verbatim', function () {
    expect(PhpLiteral::numberLiteral(0))->toBe('0')
        ->and(PhpLiteral::numberLiteral(42))->toBe('42')
        ->and(PhpLiteral::numberLiteral(-7))->toBe('-7');
});

it('leaves a normal-range float untouched (no drift)', function () {
    expect(PhpLiteral::numberLiteral(0.5))->toBe('0.5')
        ->and(PhpLiteral::numberLiteral(1.5))->toBe('1.5')
        ->and(PhpLiteral::numberLiteral(0.1))->toBe('0.1')
        ->and(PhpLiteral::numberLiteral(0.0001))->toBe('0.0001')
        ->and(PhpLiteral::numberLiteral(2.0))->toBe('2')
        ->and(PhpLiteral::numberLiteral(300000000.0))->toBe('300000000');
});

it('expands a small-magnitude float out of scientific notation (#148)', function () {
    expect(PhpLiteral::numberLiteral(1e-7))->toBe('0.0000001')
        ->and(PhpLiteral::numberLiteral(1.5e-5))->toBe('0.000015')
        ->and(PhpLiteral::numberLiteral(1.23e-10))->toBe('0.000000000123')
        ->and(PhpLiteral::numberLiteral(-2.5e-8))->toBe('-0.000000025');
});

it('expands a large-magnitude float out of scientific notation (#148)', function () {
    expect(PhpLiteral::numberLiteral(1e20))->toBe('100000000000000000000')
        ->and(PhpLiteral::numberLiteral(1e15))->toBe('1000000000000000');
});

it('never emits the letter E for any of a range of magnitudes (#148)', function () {
    foreach ([1e-7, 1e-12, 1.5e-9, 1e20, 9.999e22, -3.3e-8, 5e-300] as $value) {
        expect(PhpLiteral::numberLiteral($value))->not->toContain('E')
            ->and(PhpLiteral::numberLiteral($value))->not->toContain('e');
    }
});

it('renders a value that parses back to the original float (#148)', function () {
    foreach ([1e-7, 1.5e-5, 1.23e-10, -2.5e-8, 1e20, 1e15, 0.5, 0.0001, 42.0] as $value) {
        expect((float) PhpLiteral::numberLiteral($value))->toBe((float) $value);
    }
});
