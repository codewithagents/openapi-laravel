<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Emitter mechanics for discriminator-aware oneOf/anyOf unions (issue #38),
 * asserting on the generated SOURCE strings. The behavioral (real-validator)
 * proofs live in tests/Feature/Emitter/DiscriminatedUnion*RoundTripTest.php;
 * this file pins the emitted shape, the morph() arms, the variant inheritance,
 * the $ref-to-base typing, the fallbacks, and determinism.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateDiscriminatorSchemas(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return (new ModelGenerator)->generate($spec);
}

/**
 * The canonical discriminated Pet union (oneOf Cat/Dog, mapping cat/dog).
 *
 * @return array<string, mixed>
 */
function petUnionSchemas(): array
{
    return [
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
            ],
            'discriminator' => [
                'propertyName' => 'petType',
                'mapping' => ['cat' => 'Cat', 'dog' => 'Dog'],
            ],
        ],
        'Cat' => [
            'type' => 'object',
            'required' => ['petType', 'meow'],
            'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['petType', 'bark'],
            'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']],
        ],
        'Holder' => [
            'type' => 'object',
            'required' => ['pet'],
            'properties' => ['pet' => ['$ref' => '#/components/schemas/Pet']],
        ],
    ];
}

it('emits an abstract PropertyMorphableData base with the morph discriminator property', function () {
    $code = generateDiscriminatorSchemas(petUnionSchemas())['PetData']->code;

    expect($code)->toContain('abstract class PetData extends Data implements PropertyMorphableData')
        ->and($code)->toContain('use Spatie\LaravelData\Contracts\PropertyMorphableData;')
        ->and($code)->toContain('use Spatie\LaravelData\Attributes\PropertyForMorph;')
        // The discriminator property carries the structural morph marker plus its
        // validation attributes (NOT a rules() method, which would clobber the
        // morph guard via the overwritten-rules path).
        ->and($code)->toContain('#[PropertyForMorph, Required, StringType]')
        ->and($code)->toContain('public readonly string $petType,')
        // No rules() method on the base.
        ->and($code)->not->toContain('public static function rules');
});

it('emits a morph() match with one arm per mapping value and a null default', function () {
    $code = generateDiscriminatorSchemas(petUnionSchemas())['PetData']->code;

    expect($code)->toContain('public static function morph(array $properties): ?string')
        ->and($code)->toContain("return match (\$properties['petType'] ?? null) {")
        ->and($code)->toContain("'cat' => CatData::class,")
        ->and($code)->toContain("'dog' => DogData::class,")
        ->and($code)->toContain('default => null,');
});

it('orders the morph() arms deterministically by discriminator value', function () {
    // Mapping declared in reverse order; arms must still come out value-sorted.
    $schemas = petUnionSchemas();
    $schemas['Pet']['discriminator']['mapping'] = ['dog' => 'Dog', 'cat' => 'Cat'];

    $code = generateDiscriminatorSchemas($schemas)['PetData']->code;

    expect(strpos($code, "'cat' => CatData::class,"))
        ->toBeLessThan(strpos($code, "'dog' => DogData::class,"));
});

it('emits each variant extending the base, forwarding the discriminator', function () {
    $files = generateDiscriminatorSchemas(petUnionSchemas());
    $cat = $files['CatData']->code;

    expect($cat)->toContain('final class CatData extends PetData')
        // Discriminator forwarded as a NON-promoted param.
        ->and($cat)->toContain('string $petType,')
        ->and($cat)->toContain('parent::__construct($petType);')
        // Own property promoted; discriminator NOT re-declared on the variant.
        ->and($cat)->toContain('public readonly string $meow,')
        ->and($cat)->not->toContain('public readonly string $petType')
        // Own rules carried; the discriminator is not in the variant rules.
        ->and($cat)->toContain("'meow' => ['required', 'string'],")
        ->and($cat)->not->toContain("'petType'");
});

it('pins a variant discriminator const with a membership rule (#disc-const)', function () {
    // Each variant declares its discriminator with a const, fixing its value.
    $schemas = petUnionSchemas();
    $schemas['Cat']['properties']['petType'] = ['type' => 'string', 'const' => 'cat'];
    $schemas['Dog']['properties']['petType'] = ['type' => 'string', 'const' => 'dog'];

    $files = generateDiscriminatorSchemas($schemas);

    // The variant pins its own discriminator value so a standalone validate()
    // rejects a mismatched discriminator, while not redeclaring the property.
    expect($files['CatData']->code)->toContain("'petType' => [Rule::in(['cat'])],")
        ->and($files['CatData']->code)->toContain('use Illuminate\Validation\Rule;')
        ->and($files['CatData']->code)->not->toContain('public readonly string $petType');
    expect($files['DogData']->code)->toContain("'petType' => [Rule::in(['dog'])],");

    // The base still enforces the discriminator presence-only (it must morph
    // across every value), so it carries no pinning rule.
    expect($files['PetData']->code)->not->toContain('Rule::in');
});

it('pins a variant discriminator declared as a single-value enum', function () {
    $schemas = petUnionSchemas();
    $schemas['Cat']['properties']['petType'] = ['type' => 'string', 'enum' => ['cat']];

    $files = generateDiscriminatorSchemas($schemas);

    expect($files['CatData']->code)->toContain("'petType' => [Rule::in(['cat'])],");
});

it('types a $ref to the base as the abstract base class', function () {
    $code = generateDiscriminatorSchemas(petUnionSchemas())['HolderData']->code;

    // The base lives in the same namespace, so it is referenced by short name
    // with no import; the property types as the abstract base so spatie morphs it.
    expect($code)->toContain('public readonly PetData $pet,')
        ->and($code)->not->toContain('public readonly mixed $pet');
});

it('uses schema names as discriminator values when no mapping is given', function () {
    $schemas = petUnionSchemas();
    unset($schemas['Pet']['discriminator']['mapping']);

    $code = generateDiscriminatorSchemas($schemas)['PetData']->code;

    // Implicit mapping: the discriminator value is each variant's schema name.
    expect($code)->toContain("'Cat' => CatData::class,")
        ->and($code)->toContain("'Dog' => DogData::class,");
});

it('emits a MapName and matches morph() on the PHP property name', function () {
    $schemas = petUnionSchemas();
    $schemas['Pet']['discriminator']['propertyName'] = 'pet_type';
    $schemas['Cat']['required'] = ['pet_type', 'meow'];
    $schemas['Cat']['properties'] = ['pet_type' => ['type' => 'string'], 'meow' => ['type' => 'string']];
    $schemas['Dog']['required'] = ['pet_type', 'bark'];
    $schemas['Dog']['properties'] = ['pet_type' => ['type' => 'string'], 'bark' => ['type' => 'string']];

    $code = generateDiscriminatorSchemas($schemas)['PetData']->code;

    expect($code)->toContain("#[MapName('pet_type')]")
        ->and($code)->toContain('public readonly string $petType,')
        // spatie keys the morph payload by the PHP property name even under
        // MapName, so the match reads $properties['petType'], not 'pet_type'.
        ->and($code)->toContain("return match (\$properties['petType'] ?? null) {");
});

it('escapes a discriminator value with quotes and slashes in the morph arm', function () {
    $schemas = petUnionSchemas();
    $schemas['Pet']['discriminator']['mapping'] = ['v1/x' => 'Cat', "it's" => 'Dog'];

    $code = generateDiscriminatorSchemas($schemas)['PetData']->code;

    expect($code)->toContain("'v1/x' => CatData::class,")
        ->and($code)->toContain("'it\\'s' => DogData::class,");
    // And the result still compiles.
    token_get_all($code, TOKEN_PARSE);
    expect(true)->toBeTrue();
});

it('falls back to presence-only when a union member is a non-object schema', function () {
    $files = generateDiscriminatorSchemas([
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Color'],
            ],
            'discriminator' => ['propertyName' => 'petType'],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
        'Color' => ['type' => 'string', 'enum' => ['red', 'green']],
    ]);

    // Pet is NOT a morphable base: a non-object member degrades it. The named
    // union becomes a non-object alias (no PetData class is emitted at all).
    expect($files)->not->toHaveKey('PetData');
    // Cat stays a plain Data class, not a variant.
    expect($files['CatData']->code)->toContain('final class CatData extends Data');
});

it('falls back when a mapped variant target is missing', function () {
    $files = generateDiscriminatorSchemas([
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Ghost'],
            ],
            'discriminator' => ['propertyName' => 'petType'],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
    ]);

    // A member $ref that does not resolve to a component schema is not an object:
    // the union degrades, no base is emitted, and no `extends GhostData` appears.
    expect($files)->not->toHaveKey('PetData');
    foreach ($files as $file) {
        expect($file->code)->not->toContain('GhostData');
    }
});

it('keeps a variant under the first base on a multi-base conflict (single inheritance)', function () {
    // Animal sorts before Pet, so Animal claims Cat first.
    $files = generateDiscriminatorSchemas([
        'Animal' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Fish'],
            ],
            'discriminator' => ['propertyName' => 'kind'],
        ],
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
            ],
            'discriminator' => ['propertyName' => 'petType'],
        ],
        'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'petType' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
        'Fish' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
    ]);

    // Cat extends the first base (Animal); the conflict never produces two
    // parents or a broken class.
    expect($files['CatData']->code)->toContain('final class CatData extends AnimalData')
        ->and($files['CatData']->code)->not->toContain('extends PetData');
    // Dog still extends Pet (Pet keeps the variant it can claim).
    expect($files['DogData']->code)->toContain('final class DogData extends PetData');
});

it('leaves an inline discriminated union (not a named schema) as presence-only', function () {
    // The discriminator sits directly under a property, not on a named schema.
    $files = generateDiscriminatorSchemas([
        'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
        'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'pet' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/Cat'],
                        ['$ref' => '#/components/schemas/Dog'],
                    ],
                    'discriminator' => ['propertyName' => 'petType'],
                ],
            ],
        ],
    ]);

    // Inline unions are out of scope this phase: the property stays the existing
    // presence-only `mixed` (issue #31), Cat/Dog stay plain Data classes.
    expect($files['HolderData']->code)->toContain('public readonly mixed $pet')
        ->and($files['CatData']->code)->toContain('final class CatData extends Data')
        ->and($files['DogData']->code)->toContain('final class DogData extends Data');
});

it('warns on a multi-base single-inheritance conflict', function () {
    $generator = new ModelGenerator;
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Animal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Fish']],
                'discriminator' => ['propertyName' => 'kind'],
            ],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'petType'],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'petType' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
            'Fish' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]],
    ];
    $generator->generate(Reader::readFromJson((string) json_encode($document), OpenApi::class));

    $warnings = $generator->warnings();
    expect($warnings)->not->toBeEmpty();
    expect(implode("\n", $warnings))->toContain('shares variant "Cat"');
});

it('warns when a discriminated union has a non-object member', function () {
    $generator = new ModelGenerator;
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Color']],
                'discriminator' => ['propertyName' => 'petType'],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['petType' => ['type' => 'string']]],
            'Color' => ['type' => 'string', 'enum' => ['red', 'green']],
        ]],
    ];
    $generator->generate(Reader::readFromJson((string) json_encode($document), OpenApi::class));

    $warnings = $generator->warnings();
    expect(implode("\n", $warnings))->toContain('has member "Color"')
        ->and(implode("\n", $warnings))->toContain('presence-only');
});

it('regenerates a discriminated spec byte-identically', function () {
    $first = generateDiscriminatorSchemas(petUnionSchemas());
    $second = generateDiscriminatorSchemas(petUnionSchemas());

    expect(array_keys($first))->toBe(array_keys($second));
    foreach ($first as $name => $file) {
        expect($file->code)->toBe($second[$name]->code);
    }
});
