<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;

/**
 * Build a minimal OpenAPI document from a components.schemas map and generate.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateMapSchemas(array $schemas): array
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

it('represents a scalar value map as array<string, int> with a wildcard value rule', function () {
    $files = generateMapSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('/** @var array<string, int> */')
        ->and($code)->toContain('public readonly ?array $counts = null')
        ->and($code)->toContain("'counts' => ['sometimes', 'array']")
        ->and($code)->toContain("'counts.*' => ['integer']")
        // A map carries the transformer so an empty map serializes as {} not [].
        ->and($code)->toContain('#[WithTransformer(MapObjectTransformer::class)]')
        ->and($code)->toContain('use Spatie\LaravelData\Attributes\WithTransformer;')
        ->and($code)->toContain('use App\Data\Support\MapObjectTransformer;');
});

it('carries the value-schema constraint into the wildcard rule', function () {
    $files = generateMapSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 10]],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('/** @var array<string, string> */')
        ->and($code)->toContain("'labels.*' => ['string', 'max:10']");
});

it('represents additionalProperties: true as array<string, mixed> with no value rule', function () {
    $files = generateMapSchemas([
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'bag' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    expect($code)->toContain('/** @var array<string, mixed> */')
        ->and($code)->toContain('public readonly ?array $bag = null')
        ->and($code)->toContain("'bag' => ['sometimes', 'array']")
        ->and($code)->not->toContain("'bag.*'")
        // An untyped map is still a map: it carries the {} transformer too.
        ->and($code)->toContain('#[WithTransformer(MapObjectTransformer::class)]');
});

it('represents a $ref value map as array<string, PriceData> with a wildcard array rule', function () {
    $files = generateMapSchemas([
        'Price' => [
            'type' => 'object',
            'properties' => ['amount' => ['type' => 'integer']],
        ],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'prices' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Price']],
            ],
        ],
    ]);

    $code = $files['HolderData']->code;

    // The map values are not a DataCollection (string keys, not int-indexed).
    expect($code)->toContain('/** @var array<string, PriceData> */')
        ->and($code)->toContain('public readonly ?array $prices = null')
        ->and($code)->not->toContain('#[DataCollectionOf')
        ->and($code)->toContain("'prices.*' => ['array']")
        ->and($code)->toContain('#[WithTransformer(MapObjectTransformer::class)]');
});

it('inlines a pure-map component at the use site instead of emitting an empty class', function () {
    $files = generateMapSchemas([
        // A pure-map component: object with only additionalProperties.
        'Language' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'lang' => ['$ref' => '#/components/schemas/Language'],
            ],
        ],
    ]);

    // No LanguageData class is emitted; the array type is inlined where used.
    expect(array_keys($files))->toBe(['HolderData'])
        ->and(array_keys($files))->not->toContain('LanguageData');

    $code = $files['HolderData']->code;

    expect($code)->toContain('/** @var array<string, int> */')
        ->and($code)->toContain('public readonly ?array $lang = null')
        ->and($code)->toContain("'lang.*' => ['integer']")
        // An inlined pure-map $ref is a map at the use site: it carries the
        // transformer too, so the empty-map {} fix reaches referenced maps.
        ->and($code)->toContain('#[WithTransformer(MapObjectTransformer::class)]');
});

it('emits named properties for a mixed object and documents the uncaptured overflow', function () {
    $files = generateMapSchemas([
        'Stuff' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'integer'],
        ],
    ]);

    $code = $files['StuffData']->code;

    // Named property emitted normally.
    expect($code)->toContain('public readonly ?string $id = null')
        ->and($code)->toContain("'id' => ['sometimes', 'string']")
        // The dynamic overflow is documented, not silently dropped.
        ->and($code)->toContain('additionalProperties')
        ->and($code)->toContain('not captured');
});

it('does not add an extra-key rule for additionalProperties: false', function () {
    $files = generateMapSchemas([
        'Closed' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'additionalProperties' => false,
        ],
    ]);

    $code = $files['ClosedData']->code;

    // Named property is fine; false is not enforced by any generated rule.
    expect($code)->toContain('public readonly ?string $id = null')
        ->and($code)->toContain("'id' => ['sometimes', 'string']")
        ->and($code)->not->toContain('not captured');
});

it('keeps a plain object (no additionalProperties) as a Data class, not an array', function () {
    // cebe defaults additionalProperties to true; a plain object must not be
    // mistaken for an untyped map.
    $files = generateMapSchemas([
        'Plain' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'properties' => ['plain' => ['$ref' => '#/components/schemas/Plain']],
        ],
    ]);

    expect(array_keys($files))->toContain('PlainData');

    $code = $files['HolderData']->code;

    // The reference resolves to the Data class, not an array.
    expect($code)->toContain('public readonly ?PlainData $plain = null')
        ->and($code)->not->toContain('array<string, mixed> */');
});

it('is deterministic: regenerating produces byte-identical output', function () {
    $schemas = [
        'Price' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'integer']]],
        'Language' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'lang' => ['$ref' => '#/components/schemas/Language'],
                'prices' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Price']],
                'labels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 10]],
                'bag' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ],
    ];

    $first = array_map(fn ($f) => $f->code, generateMapSchemas($schemas));
    $second = array_map(fn ($f) => $f->code, generateMapSchemas($schemas));

    expect($first)->toBe($second);
});
