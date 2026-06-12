<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;

/**
 * #104 T1: ReferenceNode is a dumb pointer carrier. It never resolves, it
 * only exposes the raw pointer string (the cebe getReference() replacement)
 * plus the 3.1 sibling summary/description overrides.
 */
it('exposes the raw pointer string verbatim', function () {
    $reference = new ReferenceNode('#/components/schemas/Pet');

    expect($reference->ref)->toBe('#/components/schemas/Pet')
        ->and($reference->pointer())->toBe('#/components/schemas/Pet');
});

it('carries the 3.1 sibling summary and description', function () {
    $reference = new ReferenceNode(
        ref: '#/components/schemas/Pet',
        summary: 'A pet',
        description: 'Referenced pet schema',
    );

    expect($reference->summary)->toBe('A pet')
        ->and($reference->description)->toBe('Referenced pet schema');
});

it('is read-only: writing the pointer throws', function () {
    $reference = new ReferenceNode('#/components/schemas/Pet');

    expect(fn () => $reference->ref = '#/elsewhere')->toThrow(Error::class, 'readonly');
});
