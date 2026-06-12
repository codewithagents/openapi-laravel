<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Unit tests for the EMISSION side of the tag-grouped data layout (issue #93,
 * the only layout): given an injected schema-to-group attribution (the test
 * seam; production computes it from the document), the model generator must
 * place each class in its group's namespace and directory, and every
 * cross-group reference (property types, DataCollectionOf targets, Rule::enum
 * classes, morph arms, the variant base) must be imported from its real
 * namespace. Attribution itself is covered by TagGroupAttributionTest.
 *
 * @param  array<string, mixed>  $schemas
 */
function groupedDocument(array $schemas): OpenApiDocument
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return $spec;
}

/**
 * One component schema hydrated through a whole-document read: the typed
 * graph has no fragment-level hydration surface (the issue #104 bridge seams
 * are gone), so test fixtures pluck their nodes from a minimal document.
 *
 * @param  array<string, mixed>  $schema
 */
function groupedSchemaNode(array $schema): SchemaNode
{
    $node = groupedDocument(['Fixture' => $schema])->components?->schemas['Fixture'] ?? null;
    expect($node)->toBeInstanceOf(SchemaNode::class);
    assert($node instanceof SchemaNode);

    return $node;
}

/**
 * One component parameter hydrated through a whole-document read, see
 * {@see groupedSchemaNode()}.
 *
 * @param  array<string, mixed>  $parameter
 */
function groupedParameterNode(array $parameter): ParameterNode
{
    $document = (new OpenApiReader)->read([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['parameters' => ['Fixture' => $parameter]],
    ]);
    $node = $document->components?->parameters['Fixture'] ?? null;
    expect($node)->toBeInstanceOf(ParameterNode::class);
    assert($node instanceof ParameterNode);

    return $node;
}

/**
 * @param  array<string, mixed>  $schemas
 * @param  array<string, string>  $groups  schema name => group
 */
function generateGrouped(array $schemas, array $groups): ModelGenerator
{
    $generator = new ModelGenerator(new GeneratorOptions(schemaGroups: $groups));
    $generator->generate(groupedDocument($schemas));

    return $generator;
}

it('emits an attributed class into its group namespace and directory, and a root class flat', function () {
    $generator = generateGrouped([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Shared' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
    ], ['Pet' => 'Pet']);

    $files = $generator->generate(groupedDocument([
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Shared' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
    ]));

    expect($files['PetData']->code)->toContain('namespace App\Data\Pet;')
        ->and($files['PetData']->filename())->toBe('Pet/PetData.php')
        ->and($files['SharedData']->code)->toContain('namespace App\Data;')
        ->and($files['SharedData']->filename())->toBe('SharedData.php');
});

it('imports a cross-group Data class reference and keeps a same-group reference unimported', function () {
    $schemas = [
        'Order' => [
            'type' => 'object',
            'properties' => [
                'item' => ['$ref' => '#/components/schemas/Item'],
                'address' => ['$ref' => '#/components/schemas/Address'],
            ],
        ],
        'Item' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]],
        'Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
    ];

    $generator = new ModelGenerator(new GeneratorOptions(schemaGroups: ['Order' => 'Order', 'Item' => 'Order']));
    $files = $generator->generate(groupedDocument($schemas));

    // Address stays at the root, so the grouped Order must import it; Item
    // shares Order's group and namespace, so it stays short-name-only.
    expect($files['OrderData']->code)->toContain('namespace App\Data\Order;')
        ->and($files['OrderData']->code)->toContain('use App\Data\Address'.'Data;')
        ->and($files['OrderData']->code)->not->toContain('use App\Data\Order\ItemData;')
        ->and($files['OrderData']->code)->toContain('public readonly ?ItemData $item')
        ->and($files['OrderData']->code)->toContain('public readonly ?AddressData $address');
});

it('imports a root class referencing INTO a group, the reverse direction', function () {
    $files = (new ModelGenerator(new GeneratorOptions(schemaGroups: ['Item' => 'Order'])))
        ->generate(groupedDocument([
            'Wrapper' => ['type' => 'object', 'properties' => ['item' => ['$ref' => '#/components/schemas/Item']]],
            'Item' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]],
        ]));

    expect($files['WrapperData']->code)->toContain('namespace App\Data;')
        ->and($files['WrapperData']->code)->toContain('use App\Data\Order\ItemData;');
});

it('groups an enum like a Data class and imports it across groups, including its Rule::enum', function () {
    $files = (new ModelGenerator(new GeneratorOptions(schemaGroups: ['Status' => 'Pet'])))
        ->generate(groupedDocument([
            'Holder' => ['type' => 'object', 'properties' => ['status' => ['$ref' => '#/components/schemas/Status']]],
            'Status' => ['type' => 'string', 'enum' => ['on', 'off']],
        ]));

    expect($files['Status']->code)->toContain('namespace App\Data\Pet;')
        ->and($files['Status']->filename())->toBe('Pet/Status.php')
        ->and($files['HolderData']->code)->toContain('use App\Data\Pet\Status;')
        ->and($files['HolderData']->code)->toContain('Rule::enum(Status::class)');
});

it('imports a cross-group DataCollectionOf element class', function () {
    $files = (new ModelGenerator(new GeneratorOptions(schemaGroups: ['Tag' => 'Meta'])))
        ->generate(groupedDocument([
            'Post' => [
                'type' => 'object',
                'properties' => ['tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']]],
            ],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
        ]));

    expect($files['PostData']->code)->toContain('use App\Data\Meta\TagData;')
        ->and($files['PostData']->code)->toContain('#[DataCollectionOf(TagData::class)]')
        ->and($files['PostData']->code)->toContain('/** @var array<int, TagData> */');
});

it('keeps nested inline classes and the Writable variant in their owner group', function () {
    $files = (new ModelGenerator(new GeneratorOptions(schemaGroups: ['Pet' => 'Pet'])))
        ->generate(groupedDocument([
            'Pet' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'home' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ],
            ],
        ]));

    expect($files['PetHomeData']->code)->toContain('namespace App\Data\Pet;')
        ->and($files['PetHomeData']->filename())->toBe('Pet/PetHomeData.php')
        ->and($files['PetWritableData']->code)->toContain('namespace App\Data\Pet;')
        ->and($files['PetWritableData']->filename())->toBe('Pet/PetWritableData.php');
});

it('wires a discriminated union across groups: extends and morph arms import correctly', function () {
    $schemas = [
        'Animal' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
            ],
            'discriminator' => ['propertyName' => 'kind'],
        ],
        'Cat' => [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string'], 'lives' => ['type' => 'integer']],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string'], 'barks' => ['type' => 'boolean']],
        ],
    ];

    // A deliberately split attribution (base + Cat grouped, Dog at the root)
    // exercises both directions: the base imports the out-of-group variant for
    // its morph() arm, and the root variant imports the base for its extends.
    $files = (new ModelGenerator(new GeneratorOptions(schemaGroups: ['Animal' => 'Zoo', 'Cat' => 'Zoo'])))
        ->generate(groupedDocument($schemas));

    expect($files['AnimalData']->code)->toContain('namespace App\Data\Zoo;')
        ->and($files['AnimalData']->code)->toContain('use App\Data\DogData;')
        ->and($files['AnimalData']->code)->not->toContain('use App\Data\Zoo\CatData;')
        ->and($files['DogData']->code)->toContain('namespace App\Data;')
        ->and($files['DogData']->code)->toContain('use App\Data\Zoo\AnimalData;')
        ->and($files['DogData']->code)->toContain('extends AnimalData')
        ->and($files['CatData']->code)->toContain('namespace App\Data\Zoo;')
        ->and($files['CatData']->code)->not->toContain('use App\Data\Zoo\AnimalData;');
});

it('places per-operation query and body classes in their operation tag group', function () {
    $generator = new ModelGenerator(new GeneratorOptions(schemaGroups: []));
    $generator->generate(groupedDocument([]));

    $parameter = groupedParameterNode([
        'name' => 'limit',
        'in' => 'query',
        'schema' => ['type' => 'integer'],
    ]);
    $queryClass = $generator->generateQueryData('ListPets', 'GET /pets', [$parameter], 'pet');
    $bodyClass = $generator->generateBodyData('CreatePet', 'POST /pets', groupedSchemaNode([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
    ]), 'pet');

    expect($queryClass)->toBe('ListPetsQueryData')
        ->and($generator->queryFiles()['ListPetsQueryData']->filename())->toBe('Pet/ListPetsQueryData.php')
        ->and($generator->queryFiles()['ListPetsQueryData']->code)->toContain('namespace App\Data\Pet;')
        ->and($bodyClass)->toBe('CreatePetRequestData')
        ->and($generator->bodyFiles()['CreatePetRequestData']->filename())->toBe('Pet/CreatePetRequestData.php')
        ->and($generator->bodyFiles()['CreatePetRequestData']->code)->toContain('namespace App\Data\Pet;')
        ->and($generator->namespaceFor('ListPetsQueryData'))->toBe('App\Data\Pet');
});

it('places a multipart body class (issue #75) in its operation tag group too', function () {
    $generator = new ModelGenerator(new GeneratorOptions(schemaGroups: []));
    $generator->generate(groupedDocument([]));

    $class = $generator->generateMultipartBodyData('UploadAvatar', 'POST /avatars', groupedSchemaNode([
        'type' => 'object',
        'properties' => ['avatar' => ['type' => 'string', 'format' => 'binary']],
    ]), 'profile');

    expect($class)->toBe('UploadAvatarRequestData')
        ->and($generator->bodyFiles()['UploadAvatarRequestData']->filename())->toBe('Profile/UploadAvatarRequestData.php')
        ->and($generator->bodyFiles()['UploadAvatarRequestData']->code)->toContain('namespace App\Data\Profile;');
});

it('keeps query and body classes flat for the reserved Support tag and a tagless caller', function () {
    $generator = new ModelGenerator;
    $generator->generate(groupedDocument([]));
    $taglessBody = $generator->generateBodyData('CreatePet', 'POST /pets', groupedSchemaNode([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
    ]));

    $grouped = new ModelGenerator(new GeneratorOptions(schemaGroups: []));
    $grouped->generate(groupedDocument([]));
    $supportBody = $grouped->generateBodyData('OpenTicket', 'POST /tickets', groupedSchemaNode([
        'type' => 'object',
        'properties' => ['subject' => ['type' => 'string']],
    ]), 'support');

    expect($taglessBody)->toBe('CreatePetRequestData')
        ->and($generator->bodyFiles()['CreatePetRequestData']->filename())->toBe('CreatePetRequestData.php')
        ->and($generator->bodyFiles()['CreatePetRequestData']->code)->toContain("namespace App\Data;\n")
        ->and($supportBody)->toBe('OpenTicketRequestData')
        ->and($grouped->bodyFiles()['OpenTicketRequestData']->filename())->toBe('OpenTicketRequestData.php');
});

it('emits byte-identical output for an injected attribution and the self-computed one', function () {
    $schemas = [
        'Customer' => [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'status' => ['$ref' => '#/components/schemas/Status'],
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
            ],
        ],
        'Status' => ['type' => 'string', 'enum' => ['on', 'off']],
        'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
    ];

    // The document declares no operations, so the self-computed attribution
    // is empty: injecting the same empty map must change nothing.
    $injected = (new ModelGenerator(new GeneratorOptions(schemaGroups: [])))->generate(groupedDocument($schemas));
    $computed = (new ModelGenerator(new GeneratorOptions))->generate(groupedDocument($schemas));

    expect(array_keys($injected))->toBe(array_keys($computed));
    foreach ($injected as $name => $file) {
        expect($file->code)->toBe($computed[$name]->code)
            ->and($file->filename())->toBe($computed[$name]->filename());
    }
});

it('generates deterministically in grouped mode: two runs are byte-identical', function () {
    $schemas = [
        'Order' => [
            'type' => 'object',
            'properties' => [
                'item' => ['$ref' => '#/components/schemas/Item'],
                'status' => ['$ref' => '#/components/schemas/Status'],
            ],
        ],
        'Item' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]],
        'Status' => ['type' => 'string', 'enum' => ['open', 'closed']],
    ];
    $groups = ['Order' => 'Order', 'Item' => 'Order'];

    $first = (new ModelGenerator(new GeneratorOptions(schemaGroups: $groups)))->generate(groupedDocument($schemas));
    $second = (new ModelGenerator(new GeneratorOptions(schemaGroups: $groups)))->generate(groupedDocument($schemas));

    expect(array_keys($first))->toBe(array_keys($second));
    foreach ($first as $name => $file) {
        expect($file->code)->toBe($second[$name]->code)
            ->and($file->filename())->toBe($second[$name]->filename());
    }
});
