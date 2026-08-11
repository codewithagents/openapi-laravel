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
 * model and controller file keyed by class name plus the collector itself, so
 * a test can assert both the emitted signature and the diagnostics channel.
 *
 * The model generator is passed as the collector's `$models` collaborator,
 * mirroring GenerationPlanner::planServer(): alias resolution needs it, and a
 * test that omitted it would not exercise the production wiring.
 *
 * @param  array<string, mixed>  $document
 * @return array{0: array<string, GeneratedFile>, 1: OperationCollector}
 */
function generateAliasResponseScaffold(array $document): array
{
    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $modelFiles = $generator->generate($spec);
    $options = new ServerOptions;

    $collector = new OperationCollector($options, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);

    return [array_merge($modelFiles, $controllers), $collector];
}

/**
 * A document with one GET whose 200 response is a `$ref` to a NAMED component,
 * plus whatever extra components the case needs. The named component is the
 * point: an array component is not a Data class, so it never lands in the
 * registry, and the response path has to resolve the alias itself.
 *
 * @param  array<string, mixed>  $components
 * @return array<string, mixed>
 */
function aliasResponseDocument(string $responseRef, array $components): array
{
    return [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/pets' => [
                'get' => [
                    'tags' => ['Pet'],
                    'operationId' => 'listPets',
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => ['application/json' => ['schema' => ['$ref' => $responseRef]]],
                        ],
                    ],
                ],
            ],
        ],
        'components' => ['schemas' => $components],
    ];
}

it('types a $ref to a named array component as a DataCollection', function () {
    // The regression this file exists for. An INLINE `type: array` response
    // has always produced DataCollection; naming that same array as a
    // component and referencing it silently degraded to JsonResponse, because
    // an array component is a type alias (never a registry entry) and the
    // response path checked the registry only.
    [$files] = generateAliasResponseScaffold(aliasResponseDocument(
        '#/components/schemas/PetList',
        [
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'PetList' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']],
        ],
    ));

    $code = $files['AbstractPetController']->code;

    expect($code)->toContain('): DataCollection;')
        ->and($code)->toContain('@return DataCollection<int, PetData>')
        ->and($code)->toContain('use Spatie\LaravelData\DataCollection;')
        // Tag-grouped data layout (issue #93): PetData is owned by the single
        // Pet tag group, so it lands in the Pet subnamespace.
        ->and($code)->toContain('use App\Data\Pet\PetData;')
        ->and($code)->not->toContain('JsonResponse');
});

it('resolves a chained alias to a named array component', function () {
    // `allOf: [{$ref: PetList}]` is a thin wrapper the alias machinery already
    // follows for properties; the response path must follow it too.
    [$files] = generateAliasResponseScaffold(aliasResponseDocument(
        '#/components/schemas/PetPage',
        [
            'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'PetList' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']],
            'PetPage' => ['allOf' => [['$ref' => '#/components/schemas/PetList']]],
        ],
    ));

    $code = $files['AbstractPetController']->code;

    expect($code)->toContain('): DataCollection;')
        ->and($code)->toContain('@return DataCollection<int, PetData>');
});

it('keeps JsonResponse for a named array component of scalars, and says so', function () {
    // An array of scalars has no Data class to collect, so JsonResponse stays
    // correct. What was NOT correct is doing it silently: the degradation has
    // to reach the diagnostics channel like every other one (issue #67).
    [$files, $collector] = generateAliasResponseScaffold(aliasResponseDocument(
        '#/components/schemas/NameList',
        [
            'NameList' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ));

    $code = $files['AbstractPetController']->code;

    expect($code)->toContain('): JsonResponse;')
        ->and($collector->warnings())->toContain(
            'Operation GET /pets: the response schema does not resolve to a generated Data class; the return type falls back to JsonResponse and the documented response shape is not enforced.',
        );
});

it('reports a plain untypeable response through the diagnostics channel', function () {
    // The bare scalar response: no alias, no Data class, nothing to type. It
    // fell back silently before, which is what let a non-conforming
    // implementation look conformant to the generator.
    [$files, $collector] = generateAliasResponseScaffold(aliasResponseDocument(
        '#/components/schemas/Tag',
        [
            'Tag' => ['type' => 'string'],
        ],
    ));

    $code = $files['AbstractPetController']->code;

    expect($code)->toContain('): JsonResponse;')
        ->and($collector->warnings())->not->toBeEmpty();
});
