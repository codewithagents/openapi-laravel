<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\DiscriminatorNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * #104 T1: the SchemaNode value object must make absent-vs-explicit
 * representable for the keywords the emitter reads through cebe's
 * getSerializableData() escape hatch today. These tests pin the presence
 * semantics (hasDefault, hasConst, hasAdditionalProperties), the dual-form
 * exclusive bounds, the boolean `required` misuse passthrough, and the
 * read-only nature of the graph.
 */
it('defaults every keyword to the absent state', function () {
    $schema = new SchemaNode;

    expect($schema->type)->toBeNull()
        ->and($schema->format)->toBeNull()
        ->and($schema->nullable)->toBeNull()
        ->and($schema->required)->toBeNull()
        ->and($schema->enum)->toBeNull()
        ->and($schema->properties)->toBeNull()
        ->and($schema->items)->toBeNull()
        ->and($schema->allOf)->toBeNull()
        ->and($schema->oneOf)->toBeNull()
        ->and($schema->anyOf)->toBeNull()
        ->and($schema->prefixItems)->toBeNull()
        ->and($schema->patternProperties)->toBeNull()
        ->and($schema->dependentRequired)->toBeNull()
        ->and($schema->contentMediaType)->toBeNull()
        ->and($schema->discriminator)->toBeNull()
        ->and($schema->hasDefault)->toBeFalse()
        ->and($schema->default)->toBeNull()
        ->and($schema->hasConst)->toBeFalse()
        ->and($schema->const)->toBeNull()
        ->and($schema->hasAdditionalProperties)->toBeFalse()
        ->and($schema->additionalProperties)->toBeNull()
        ->and($schema->extensions)->toBe([])
        ->and($schema->extra)->toBe([]);
});

it('distinguishes an explicit null default from an absent default', function () {
    $absent = new SchemaNode;
    $explicitNull = new SchemaNode(default: null, hasDefault: true);

    expect($absent->hasDefault)->toBeFalse()
        ->and($explicitNull->hasDefault)->toBeTrue()
        ->and($explicitNull->default)->toBeNull();
});

it('distinguishes an explicit null const from an absent const', function () {
    $explicitNull = new SchemaNode(const: null, hasConst: true);
    $value = new SchemaNode(const: 'dog', hasConst: true);

    expect($explicitNull->hasConst)->toBeTrue()
        ->and($explicitNull->const)->toBeNull()
        ->and($value->const)->toBe('dog');
});

it('distinguishes explicit additionalProperties false from an absent key', function () {
    $absent = new SchemaNode;
    $closed = new SchemaNode(additionalProperties: false, hasAdditionalProperties: true);
    $typed = new SchemaNode(
        additionalProperties: new SchemaNode(type: 'string'),
        hasAdditionalProperties: true,
    );

    expect($absent->hasAdditionalProperties)->toBeFalse()
        ->and($closed->additionalProperties)->toBeFalse()
        ->and($closed->hasAdditionalProperties)->toBeTrue()
        ->and($typed->additionalProperties)->toBeInstanceOf(SchemaNode::class);
});

it('carries both the 3.0 boolean and the 3.1 numeric exclusive bound forms', function () {
    $boolForm = new SchemaNode(minimum: 5, exclusiveMinimum: true);
    $numericForm = new SchemaNode(exclusiveMinimum: 5, exclusiveMaximum: 9.5);

    expect($boolForm->exclusiveMinimum)->toBeTrue()
        ->and($boolForm->minimum)->toBe(5)
        ->and($numericForm->exclusiveMinimum)->toBe(5)
        ->and($numericForm->exclusiveMaximum)->toBe(9.5);
});

it('keeps the real-world boolean required misuse representable', function () {
    $proper = new SchemaNode(required: ['id', 'name']);
    $misuse = new SchemaNode(required: true);

    expect($proper->required)->toBe(['id', 'name'])
        ->and($misuse->required)->toBeTrue();
});

it('types the 3.1 keywords first-class', function () {
    $schema = new SchemaNode(
        type: ['string', 'null'],
        prefixItems: [new SchemaNode(type: 'integer'), new ReferenceNode('#/components/schemas/Pet')],
        patternProperties: ['^x-' => new SchemaNode(type: 'string')],
        dependentRequired: ['creditCard' => ['billingAddress']],
        contentMediaType: 'image/png',
    );

    expect($schema->type)->toBe(['string', 'null'])
        ->and($schema->prefixItems)->toHaveCount(2)
        ->and($schema->prefixItems[1])->toBeInstanceOf(ReferenceNode::class)
        ->and($schema->patternProperties)->toHaveKey('^x-')
        ->and($schema->dependentRequired)->toBe(['creditCard' => ['billingAddress']])
        ->and($schema->contentMediaType)->toBe('image/png');
});

it('types the vendor deprecation extensions and keeps the raw extension bag', function () {
    $schema = new SchemaNode(
        deprecated: true,
        xDeprecatedReason: 'use NewPet instead',
        xDeprecationReason: 'legacy field',
        extensions: ['x-internal' => true],
        extra: ['examples' => ['a', 'b']],
    );

    expect($schema->xDeprecatedReason)->toBe('use NewPet instead')
        ->and($schema->xDeprecationReason)->toBe('legacy field')
        ->and($schema->extensions)->toBe(['x-internal' => true])
        ->and($schema->extra)->toBe(['examples' => ['a', 'b']]);
});

it('nests discriminators with the 3.2 defaultMapping stub typed from day one', function () {
    $schema = new SchemaNode(
        oneOf: [new ReferenceNode('#/components/schemas/Cat'), new ReferenceNode('#/components/schemas/Dog')],
        discriminator: new DiscriminatorNode(
            propertyName: 'petType',
            mapping: ['cat' => '#/components/schemas/Cat'],
            defaultMapping: '#/components/schemas/Dog',
        ),
    );

    expect($schema->discriminator?->propertyName)->toBe('petType')
        ->and($schema->discriminator->mapping)->toBe(['cat' => '#/components/schemas/Cat'])
        ->and($schema->discriminator->defaultMapping)->toBe('#/components/schemas/Dog');
});

it('is read-only: writing a property throws', function () {
    $schema = new SchemaNode(type: 'string');

    expect(fn () => $schema->type = 'integer')->toThrow(Error::class, 'readonly');
});
