<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Build a server scaffold from an inline document, returning every generated
 * model and controller file keyed by class name.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, GeneratedFile>
 */
function generateUnionResponseScaffold(array $document): array
{
    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($spec);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry()))->collect($spec);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);

    return array_merge($modelFiles, $controllers);
}

/**
 * A document with one GET whose 200 response is a oneOf/anyOf of $refs.
 *
 * @param  array<string, mixed>  $responseSchema
 * @return array<string, mixed>
 */
function unionResponseDocument(array $responseSchema): array
{
    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/pet' => [
                'get' => [
                    'tags' => ['Pet'],
                    'operationId' => 'getPet',
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => ['application/json' => ['schema' => $responseSchema]],
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
                'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'string']]],
            ],
        ],
    ];
}

it('types a oneOf-of-Data-class response as a Data-class union return', function () {
    $files = generateUnionResponseScaffold(unionResponseDocument([
        'oneOf' => [
            ['$ref' => '#/components/schemas/Cat'],
            ['$ref' => '#/components/schemas/Dog'],
        ],
    ]));

    $code = $files['AbstractPetController']->code;

    expect($code)->toContain('): CatData|DogData;')
        ->and($code)->toContain('use App\Data\CatData;')
        ->and($code)->toContain('use App\Data\DogData;')
        // The union return must not regress to JsonResponse.
        ->and($code)->not->toContain('JsonResponse');
});

it('keeps JsonResponse when a union response member is not a Data class', function () {
    $files = generateUnionResponseScaffold(unionResponseDocument([
        'oneOf' => [
            ['$ref' => '#/components/schemas/Cat'],
            ['type' => 'string'],
        ],
    ]));

    $code = $files['AbstractPetController']->code;

    // A scalar variant cannot be a Data-class return: fall back to JsonResponse.
    expect($code)->toContain('): JsonResponse;')
        ->and($code)->toContain('use Illuminate\Http\JsonResponse;');
});

it('produces a union-return controller with no unresolved class reference', function () {
    $files = generateUnionResponseScaffold(unionResponseDocument([
        'anyOf' => [
            ['$ref' => '#/components/schemas/Cat'],
            ['$ref' => '#/components/schemas/Dog'],
        ],
    ]));

    $defined = definedClassNames(array_values($files));
    $code = $files['AbstractPetController']->code;

    expect(unresolvedSignatureTypes($code, $defined))->toBe([]);
});
