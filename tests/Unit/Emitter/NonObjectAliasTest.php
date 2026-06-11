<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Regression coverage for issue #9: a top-level component schema that is itself
 * a scalar, an array, or a oneOf/anyOf union (no object `properties`) must be
 * treated as a TYPE ALIAS, not emitted as an empty Data class and referenced as
 * a property type. An empty FooData class silently fails to hydrate the value.
 *
 * Build a minimal OpenAPI document from a components.schemas map and generate.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateAliasSchemas(array $schemas, string $version = '3.0.3'): array
{
    $document = [
        'openapi' => $version,
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return (new ModelGenerator)->generate($spec);
}

it('aliases a scalar component to its scalar type with the format rule, no empty class', function () {
    $files = generateAliasSchemas([
        // k8s `Time`: a component that is itself a date-time string.
        'Time' => ['type' => 'string', 'format' => 'date-time'],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'at' => ['$ref' => '#/components/schemas/Time'],
            ],
        ],
    ]);

    // No empty TimeData class is emitted: the scalar is inlined at the use site.
    expect(array_keys($files))->toBe(['HolderData'])
        ->and(array_keys($files))->not->toContain('TimeData');

    $code = $files['HolderData']->code;

    // The property is typed string and carries the date-time rule.
    expect($code)->toContain('public readonly ?string $at = null')
        ->and($code)->toContain('new Rfc3339DateTimeRule')
        ->and($code)->toContain('use App\Data\Support\Rfc3339DateTimeRule;')
        ->and($code)->not->toContain('TimeData');
});

it('carries a length-bounded string alias constraint into the use site', function () {
    $files = generateAliasSchemas([
        'ShortString' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10],
        'Holder' => [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['$ref' => '#/components/schemas/ShortString'],
            ],
        ],
    ]);

    expect(array_keys($files))->not->toContain('ShortStringData');

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly string $name')
        ->and($code)->toContain("'name' => ['required', 'string', 'max:10', 'min:1']");
});

it('aliases an array component to its array type', function () {
    $files = generateAliasSchemas([
        'Tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'tags' => ['$ref' => '#/components/schemas/Tags'],
            ],
        ],
    ]);

    expect(array_keys($files))->not->toContain('TagsData');

    $code = $files['HolderData']->code;

    // Resolves to the array type plus the item ('array' + per-item) rules.
    expect($code)->toContain('/** @var array<int, string> */')
        ->and($code)->toContain('public readonly ?array $tags = null')
        ->and($code)->toContain("'tags' => ['sometimes', 'array']")
        ->and($code)->toContain("'tags.*' => ['string']");
});

it('aliases an array-of-$ref component to a DataCollection at the use site', function () {
    $files = generateAliasSchemas([
        'Item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'Items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'items' => ['$ref' => '#/components/schemas/Items'],
            ],
        ],
    ]);

    expect(array_keys($files))->toContain('ItemData')
        ->and(array_keys($files))->not->toContain('ItemsData');

    $code = $files['HolderData']->code;

    expect($code)->toContain('/** @var array<int, ItemData> */')
        ->and($code)->toContain('#[DataCollectionOf(ItemData::class)]')
        ->and($code)->toContain('public readonly ?array $items = null');
});

it('aliases a oneOf scalar union component to a native union with presence rules, no empty class', function () {
    $files = generateAliasSchemas([
        // k8s `IntOrString`: oneOf of integer and string.
        'IntOrString' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'required' => ['port'],
            'properties' => [
                'port' => ['$ref' => '#/components/schemas/IntOrString'],
            ],
        ],
    ]);

    expect(array_keys($files))->not->toContain('IntOrStringData');

    $code = $files['HolderData']->code;

    // A required union: native PHP union type, presence-only rule.
    expect($code)->toContain('public readonly int|string $port')
        ->and($code)->toContain("'port' => ['required']")
        ->and($code)->not->toContain('IntOrStringData');
});

it('aliases an anyOf scalar union component the same way', function () {
    $files = generateAliasSchemas([
        'StringOrBool' => ['anyOf' => [['type' => 'string'], ['type' => 'boolean']]],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'flag' => ['$ref' => '#/components/schemas/StringOrBool'],
            ],
        ],
    ]);

    expect(array_keys($files))->not->toContain('StringOrBoolData');

    $code = $files['HolderData']->code;

    expect($code)->toContain('string|bool|null $flag = null');
});

it('resolves a chained alias (A -> B scalar) transitively', function () {
    $files = generateAliasSchemas([
        // A is a bare $ref to B; B is a scalar. A property $ref to A must resolve
        // through A to B's scalar type, never to an empty AData/BData class.
        'A' => ['allOf' => [['$ref' => '#/components/schemas/B']]],
        'B' => ['type' => 'string', 'format' => 'date-time'],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'at' => ['$ref' => '#/components/schemas/A'],
            ],
        ],
    ]);

    // Neither AData nor BData is emitted.
    expect(array_keys($files))->toBe(['HolderData'])
        ->and(array_keys($files))->not->toContain('AData')
        ->and(array_keys($files))->not->toContain('BData');

    $code = $files['HolderData']->code;

    expect($code)->toContain('public readonly ?string $at = null')
        ->and($code)->toContain('new Rfc3339DateTimeRule');
});

it('still emits an empty Data class for a genuinely empty object component', function () {
    $files = generateAliasSchemas([
        // type: object with no properties is a LEGITIMATELY EMPTY OBJECT and must
        // still emit an (empty) Data class: do not over-apply the alias fix.
        'Blank' => ['type' => 'object'],
        'BlankExplicit' => ['type' => 'object', 'properties' => new stdClass],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'a' => ['$ref' => '#/components/schemas/Blank'],
                'b' => ['$ref' => '#/components/schemas/BlankExplicit'],
            ],
        ],
    ]);

    expect(array_keys($files))->toContain('BlankData')
        ->and(array_keys($files))->toContain('BlankExplicitData');

    // The empty object class really is empty (extends Data {}), and the property
    // resolves to that Data class, not to mixed/array.
    expect($files['BlankData']->code)->toContain('final class BlankData extends Data')
        ->and($files['BlankData']->code)->toMatch('/extends Data\s*\{\s*\}\s*$/');

    $holder = $files['HolderData']->code;
    expect($holder)->toContain('public readonly ?BlankData $a = null')
        ->and($holder)->toContain('public readonly ?BlankExplicitData $b = null');
});

it('is deterministic: regenerating non-object aliases produces byte-identical output', function () {
    $schemas = [
        'Time' => ['type' => 'string', 'format' => 'date-time'],
        'Tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'IntOrString' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
        'Holder' => [
            'type' => 'object',
            'properties' => [
                'at' => ['$ref' => '#/components/schemas/Time'],
                'tags' => ['$ref' => '#/components/schemas/Tags'],
                'port' => ['$ref' => '#/components/schemas/IntOrString'],
            ],
        ],
    ];

    $first = array_map(fn ($f) => $f->code, generateAliasSchemas($schemas));
    $second = array_map(fn ($f) => $f->code, generateAliasSchemas($schemas));

    expect($first)->toBe($second);
});

it('produces no empty classes for the issue #9 conformance aliases', function () {
    // The conformance fixture defines ScalarAlias (string), ArrayAlias (array),
    // and OneOfAlias (oneOf int|string) as non-object alias components. None must
    // become an empty Data class.
    // Go through SpecParser (not Reader directly) so the SchemaNormalizer runs:
    // the fixture's closed tuple uses a boolean `items: false` that cebe cannot
    // instantiate raw, which the normalizer rewrites before cebe sees it.
    $path = dirname(__DIR__, 2).'/Fixtures/conformance/conformance-3.1.yaml';
    $spec = (new SpecParser)->parseFile($path);
    $files = (new ModelGenerator)->generate($spec);

    expect(array_keys($files))->not->toContain('ScalarAliasData')
        ->and(array_keys($files))->not->toContain('ArrayAliasData')
        ->and(array_keys($files))->not->toContain('OneOfAliasData');
});
