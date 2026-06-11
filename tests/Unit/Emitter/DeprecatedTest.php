<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Build a minimal OpenAPI document from a components.schemas map and generate.
 * Self-contained inline spec so these tests do not touch the shared fixtures.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateDeprecatedSchemas(array $schemas): array
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

it('emits a class-level @deprecated for a deprecated component schema', function () {
    $code = generateDeprecatedSchemas([
        'Old' => [
            'type' => 'object',
            'deprecated' => true,
            'properties' => ['id' => ['type' => 'integer']],
        ],
    ])['OldData']->code;

    // The @deprecated tag sits in a class-level docblock above the class.
    expect($code)->toContain("/**\n * @deprecated\n */\nfinal class OldData extends Data");
});

it('appends a sanitized reason from x-deprecated-reason', function () {
    $code = generateDeprecatedSchemas([
        'Old' => [
            'type' => 'object',
            'deprecated' => true,
            'x-deprecated-reason' => 'use New instead',
            'properties' => ['id' => ['type' => 'integer']],
        ],
    ])['OldData']->code;

    expect($code)->toContain('@deprecated use New instead');
});

it('accepts the alternate x-deprecation-reason spelling', function () {
    $code = generateDeprecatedSchemas([
        'Old' => [
            'type' => 'object',
            'deprecated' => true,
            'x-deprecation-reason' => 'gone soon',
            'properties' => ['id' => ['type' => 'integer']],
        ],
    ])['OldData']->code;

    expect($code)->toContain('@deprecated gone soon');
});

it('does not emit @deprecated for a non-deprecated schema', function () {
    $code = generateDeprecatedSchemas([
        'Plain' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ],
    ])['PlainData']->code;

    expect($code)->not->toContain('@deprecated');
});

it('emits @deprecated on a deprecated property as a single-line docblock', function () {
    $code = generateDeprecatedSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'legacy' => ['type' => 'string', 'deprecated' => true],
                'current' => ['type' => 'string'],
            ],
        ],
    ])['HolderData']->code;

    // The deprecated property gets its own one-line docblock; the non-deprecated
    // sibling gets none.
    expect($code)->toContain("/** @deprecated */\n        public readonly ?string \$legacy = null")
        ->and($code)->toContain('public readonly ?string $current = null');

    // Only one @deprecated overall (the legacy property), not on current.
    expect(substr_count($code, '@deprecated'))->toBe(1);
});

it('composes @deprecated with an existing @var on the same property', function () {
    $code = generateDeprecatedSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                // An array property carries a @var generic; deprecated adds a tag.
                'tags' => ['type' => 'array', 'deprecated' => true, 'items' => ['type' => 'string']],
            ],
        ],
    ])['HolderData']->code;

    // Both tags appear in a multi-line block, @var first then @deprecated, with a
    // blank ` *` separator between the two distinct annotation groups so the
    // Laravel Pint `phpdoc_separation` fixer leaves the output untouched.
    expect($code)->toContain("        /**\n         * @var array<int, string>\n         *\n         * @deprecated\n         */\n        public readonly ?array \$tags");
});

it('emits a sanitized reason on a deprecated property', function () {
    $code = generateDeprecatedSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'legacy' => ['type' => 'string', 'deprecated' => true, 'x-deprecation-reason' => 'removed in v2'],
            ],
        ],
    ])['HolderData']->code;

    expect($code)->toContain('/** @deprecated removed in v2 */');
});

it('emits @deprecated on a deprecated enum component', function () {
    $code = generateDeprecatedSchemas([
        'Color' => ['type' => 'string', 'deprecated' => true, 'enum' => ['red', 'green']],
    ])['Color']->code;

    expect($code)->toContain("/**\n * @deprecated\n */\nenum Color: string");
});

it('emits @deprecated on a deprecated discriminated base and variant', function () {
    $files = generateDeprecatedSchemas([
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
            ],
            'deprecated' => true,
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => 'Cat', 'dog' => 'Dog']],
        ],
        'Cat' => [
            'type' => 'object',
            'deprecated' => true,
            'required' => ['petType'],
            'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string', 'deprecated' => true]],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['petType'],
            'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']],
        ],
    ]);

    // The deprecated base carries the class-level tag.
    expect($files['PetData']->code)->toContain("/**\n * @deprecated\n */\nabstract class PetData");
    // A deprecated variant carries it too, plus a deprecated property tag.
    expect($files['CatData']->code)->toContain("/**\n * @deprecated\n */\nfinal class CatData extends PetData")
        ->and($files['CatData']->code)->toContain('/** @deprecated */');
    // A non-deprecated variant does not.
    expect($files['DogData']->code)->not->toContain('@deprecated');
});

it('composes a class @deprecated with the additionalProperties overflow note', function () {
    $code = generateDeprecatedSchemas([
        'Bag' => [
            'type' => 'object',
            'deprecated' => true,
            'properties' => ['id' => ['type' => 'integer']],
            'additionalProperties' => ['type' => 'string'],
        ],
    ])['BagData']->code;

    // Both class-level doc lines appear, @deprecated first then the note.
    expect($code)->toContain("/**\n * @deprecated\n * This schema also declares additionalProperties:");
});

it('neutralizes a docblock-injection payload in a deprecation reason', function () {
    $code = generateDeprecatedSchemas([
        'Evil' => [
            'type' => 'object',
            'deprecated' => true,
            'x-deprecated-reason' => "x */ } system('boom'); /*",
            'properties' => ['id' => ['type' => 'integer']],
        ],
    ])['EvilData']->code;

    // The injected */ is neutralized to * / so the comment never closes early.
    expect($code)->toContain('@deprecated x * / } system')
        ->and($code)->not->toContain('*/ } system');

    // And the result still parses as valid PHP with the payload inert.
    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(Throwable::class);
});

it('is deterministic: regenerating a deprecated spec is byte-identical', function () {
    $schemas = [
        'Old' => [
            'type' => 'object',
            'deprecated' => true,
            'x-deprecated-reason' => 'use New',
            'properties' => [
                'id' => ['type' => 'integer'],
                'legacy' => ['type' => 'string', 'deprecated' => true],
            ],
        ],
    ];

    $first = generateDeprecatedSchemas($schemas);
    $second = generateDeprecatedSchemas($schemas);

    expect(array_keys($first))->toBe(array_keys($second));
    foreach ($first as $name => $file) {
        expect($file->code)->toBe($second[$name]->code);
    }
});
