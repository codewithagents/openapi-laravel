<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;

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

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

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
