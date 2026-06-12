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
 * Regression for the unimported-`Request` bug: an operation whose requestBody is
 * a `$ref` to #/components/requestBodies/... is left unresolved by the parser by
 * design, so the collector cannot type it and falls back to injecting a Request.
 * That fallback must push the Request import, exactly like the inline-body and
 * unresolvable-schema fallbacks, or the controller references Request with no
 * matching `use` statement.
 */
function generateRefBodyController(): string
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/widgets' => [
                'post' => [
                    'tags' => ['Widget'],
                    'operationId' => 'createWidget',
                    'requestBody' => ['$ref' => '#/components/requestBodies/ABody'],
                    'responses' => ['204' => ['description' => 'created']],
                ],
            ],
        ],
        'components' => [
            'requestBodies' => [
                'ABody' => [
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ],
        ],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry()))->collect($spec);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);

    return $controllers['AbstractWidgetController']->code;
}

it('injects a Request param for a $ref requestBody and imports Request', function () {
    $code = generateRefBodyController();

    expect($code)->toContain('use Illuminate\Http\Request;')
        ->and($code)->toContain('Request $request');
});

it('produces a $ref-requestBody controller with no unresolved class reference', function () {
    $code = generateRefBodyController();

    $defined = definedClassNames([
        new GeneratedFile('AbstractWidgetController', $code),
    ]);

    expect(unresolvedSignatureTypes($code, $defined))->toBe([]);
});

/**
 * Issue #9, server side: a request/response `$ref` to a non-object alias
 * component (scalar / oneOf) must NOT type the method against an empty alias
 * Data class (which no longer exists). The collector falls back to a typed
 * Request param / JsonResponse return, and the controller never references a
 * missing class.
 */
function generateAliasRefController(): string
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'post' => [
                    'tags' => ['Thing'],
                    'operationId' => 'createThing',
                    'requestBody' => [
                        'content' => [
                            // Body schema is a $ref to a scalar alias component.
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/ScalarAlias']],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => [
                                // Response schema is a $ref to a oneOf alias component.
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/OneOfAlias']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'ScalarAlias' => ['type' => 'string', 'format' => 'date-time'],
                'OneOfAlias' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
        ],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $files = $generator->generate($spec);

    // No alias Data classes are emitted, so the registry has no entry to point at.
    expect(array_keys($files))->not->toContain('ScalarAliasData')
        ->and(array_keys($files))->not->toContain('OneOfAliasData');

    $options = new ServerOptions;
    $descriptors = (new OperationCollector($options, $generator->registry()))->collect($spec);
    $controllers = (new ControllerGenerator($options))->generate($descriptors);

    return $controllers['AbstractThingController']->code;
}

it('does not reference an empty alias Data class from a $ref body/response', function () {
    $code = generateAliasRefController();

    // The alias body/response fall back to Request / JsonResponse, never to a
    // ScalarAliasData/OneOfAliasData reference.
    expect($code)->not->toContain('ScalarAliasData')
        ->and($code)->not->toContain('OneOfAliasData')
        ->and($code)->toContain('Request $request')
        ->and($code)->toContain(': JsonResponse');
});

it('produces an alias-ref controller with no unresolved class reference', function () {
    $code = generateAliasRefController();

    $defined = definedClassNames([
        new GeneratedFile('AbstractThingController', $code),
    ]);

    expect(unresolvedSignatureTypes($code, $defined))->toBe([]);
});
