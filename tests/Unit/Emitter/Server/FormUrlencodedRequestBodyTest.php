<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Issue #130: an application/x-www-form-urlencoded object request-body schema
 * is structurally identical to a JSON object body (Laravel parses urlencoded
 * input into $request->all() exactly like JSON), so it routes through the SAME
 * generateBodyData() pipeline and types the controller param the same way. JSON
 * still wins when an operation declares both; a non-object urlencoded body
 * keeps the documented Request fallback.
 *
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectFormBody(array $paths, array $schemas = []): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
    if ($schemas !== []) {
        $document['components'] = ['schemas' => $schemas];
    }

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);

    return [$descriptors, $generator, $collector];
}

function formPetPaths(array $content): array
{
    return [
        '/pets' => [
            'post' => [
                'tags' => ['Pet'],
                'operationId' => 'createPet',
                'requestBody' => ['content' => $content],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ];
}

it('types a form-urlencoded object request body as a synthesized per-operation Data param', function () {
    [$descriptors, $generator] = collectFormBody(formPetPaths([
        'application/x-www-form-urlencoded' => ['schema' => [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 10],
                'age' => ['type' => 'integer', 'minimum' => 0],
            ],
        ]],
    ]));

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData'])
        ->and($descriptors[0]->bodyRequiresRequest)->toBeFalse()
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\CreatePetRequestData')
        ->and($descriptors[0]->imports)->not->toContain('Illuminate\\Http\\Request');

    $files = $generator->bodyFiles();
    expect($files)->toHaveKey('CreatePetRequestData');

    $code = $files['CreatePetRequestData']->code;
    expect($code)->toContain('Request body of POST /pets.')
        ->and($code)->toContain('final class CreatePetRequestData extends Data')
        ->and($code)->toContain('public readonly string $name')
        ->and($code)->toContain("'name' => ['required', 'string', 'max:10']")
        ->and($code)->toContain("'age' => ['sometimes', 'integer', 'min:0']");
});

it('renders the typed form-urlencoded body param into the abstract controller signature', function () {
    [$descriptors] = collectFormBody(formPetPaths([
        'application/x-www-form-urlencoded' => ['schema' => [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]],
    ]));

    $controllers = (new ControllerGenerator(new ServerOptions))->generate($descriptors);
    $code = $controllers['AbstractPetController']->code;

    expect($code)->toContain('use App\Data\Pet\CreatePetRequestData;')
        ->and($code)->toContain('abstract public function store(CreatePetRequestData $body): JsonResponse;');
});

it('ignores a content-type parameter on the form-urlencoded media type', function () {
    [$descriptors] = collectFormBody(formPetPaths([
        'application/x-www-form-urlencoded; charset=utf-8' => ['schema' => [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]],
    ]));

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData']);
});

it('reuses a component Data class for a form-urlencoded $ref body', function () {
    [$descriptors] = collectFormBody(
        formPetPaths([
            'application/x-www-form-urlencoded' => ['schema' => ['$ref' => '#/components/schemas/Pet']],
        ]),
        [
            'Pet' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        ],
    );

    // A schema-level $ref reuses the existing component class, like the JSON
    // $ref path, instead of synthesizing a per-operation class.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'pet', 'type' => 'PetData'])
        ->and($descriptors[0]->bodyRequiresRequest)->toBeFalse()
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\PetData');
});

it('picks JSON over form-urlencoded when an operation declares both (precedence)', function () {
    [$descriptors, $generator] = collectFormBody(formPetPaths([
        'application/json' => ['schema' => [
            'type' => 'object',
            'properties' => ['jsonField' => ['type' => 'string']],
        ]],
        'application/x-www-form-urlencoded' => ['schema' => [
            'type' => 'object',
            'properties' => ['formField' => ['type' => 'string']],
        ]],
    ]));

    // JSON wins: the synthesized class carries the JSON properties, never the
    // form ones.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData']);

    $code = $generator->bodyFiles()['CreatePetRequestData']->code;
    expect($code)->toContain('$jsonField')
        ->and($code)->not->toContain('$formField');
});

it('picks multipart over form-urlencoded when an operation declares both (precedence)', function () {
    [$descriptors, $generator] = collectFormBody(formPetPaths([
        'multipart/form-data' => ['schema' => [
            'type' => 'object',
            'properties' => ['multipartField' => ['type' => 'string']],
        ]],
        'application/x-www-form-urlencoded' => ['schema' => [
            'type' => 'object',
            'properties' => ['formField' => ['type' => 'string']],
        ]],
    ]));

    // Multipart wins over form-urlencoded: precedence is JSON > multipart >
    // form-urlencoded.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'CreatePetRequestData']);

    $code = $generator->bodyFiles()['CreatePetRequestData']->code;
    expect($code)->toContain('$multipartField')
        ->and($code)->not->toContain('$formField');
});

it('keeps the Request fallback for a non-object form-urlencoded body', function () {
    [$descriptors, $generator] = collectFormBody(formPetPaths([
        'application/x-www-form-urlencoded' => ['schema' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]],
    ]));

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($descriptors[0]->imports)->toContain('Illuminate\\Http\\Request')
        ->and($generator->bodyFiles())->toBe([]);
});

it('does not type a form-urlencoded body when no model generator is wired in (legacy call sites)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => formPetPaths([
            'application/x-www-form-urlencoded' => ['schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ]],
        ]),
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry());
    $descriptors = $collector->collect($spec);

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($collector->warnings())->toContain(
            'Operation POST /pets: the request body is application/x-www-form-urlencoded and no model generator is wired in to synthesize a Data class; the controller method falls back to Illuminate\Http\Request.',
        );
});

it('generates byte-identical form-urlencoded body classes across two runs (determinism)', function () {
    $run = function (): array {
        [, $generator] = collectFormBody(formPetPaths([
            'application/x-www-form-urlencoded' => ['schema' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'age' => ['type' => 'integer'],
                ],
            ]],
        ]));

        return array_map(fn ($file) => $file->code, $generator->bodyFiles());
    };

    expect($run())->toBe($run());
});
