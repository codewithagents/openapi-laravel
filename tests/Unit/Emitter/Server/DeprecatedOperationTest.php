<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * A spec operation marked `deprecated: true` must give its abstract controller
 * method an `@deprecated` docblock line, symmetric with the tag a deprecated
 * schema gives its generated Data class. OpenAPI's operation `deprecated` is a
 * bare boolean with no reason field, so the line stays a plain `@deprecated`.
 *
 * @return array<string, string> abstract class name => generated code
 */
function generateDeprecatedOperationControllers(): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/legacy' => [
                'get' => [
                    'tags' => ['Thing'],
                    'operationId' => 'getLegacy',
                    'summary' => 'A retired endpoint.',
                    'deprecated' => true,
                    'responses' => ['204' => ['description' => 'No content']],
                ],
            ],
            '/current' => [
                'get' => [
                    'tags' => ['Thing'],
                    'operationId' => 'getCurrent',
                    'summary' => 'A live endpoint.',
                    'responses' => ['204' => ['description' => 'No content']],
                ],
            ],
        ],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $options = new ServerOptions;

    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($spec);
    $files = (new ControllerGenerator($options))->generate($descriptors);

    return array_map(fn ($file) => $file->code, $files);
}

it('emits an @deprecated docblock on the method of a deprecated operation', function () {
    $code = generateDeprecatedOperationControllers()['AbstractThingController'];

    // The deprecated operation's method carries the tag.
    expect($code)->toContain('     * @deprecated')
        ->and($code)->toContain('public function getLegacy(');

    // It rides in the same docblock as the summary, not as loose text.
    $deprecatedMethod = substr($code, (int) strpos($code, 'A retired endpoint.'), 200);
    expect($deprecatedMethod)->toContain('@deprecated');
});

it('does not emit @deprecated on a non-deprecated operation', function () {
    $code = generateDeprecatedOperationControllers()['AbstractThingController'];

    // Only one @deprecated line across the whole controller: the legacy method.
    expect(substr_count($code, '@deprecated'))->toBe(1);

    // The live method's docblock (between its summary and its signature) has none.
    $liveStart = (int) strpos($code, 'A live endpoint.');
    $liveBlock = substr($code, $liveStart, (int) strpos($code, 'public function getCurrent(') - $liveStart);
    expect($liveBlock)->not->toContain('@deprecated');
});
