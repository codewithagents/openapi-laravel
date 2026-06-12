<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Regression for the `this` property fatal: `$this` is the one identifier PHP
 * forbids as a parameter variable ("Cannot use $this as parameter"). A property
 * literally named `this` must therefore be renamed (to `_this`) with a
 * #[MapName('this')] so the wire key still round-trips, and the generated file
 * must actually compile, which token_get_all(TOKEN_PARSE) does NOT verify (it
 * parses `$this` as a valid token, the fatal only surfaces at compile time).
 */
function generateThisHolder(): GeneratedFile
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => [
            'schemas' => [
                'Holder' => [
                    'type' => 'object',
                    'properties' => [
                        'this' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return (new ModelGenerator)->generate($spec)['HolderData'];
}

it('renames a `this` property to `$_this` with a MapName preserving the wire key', function () {
    $code = generateThisHolder()->code;

    expect($code)->not->toContain('$this =')
        ->and($code)->toContain('$_this')
        ->and($code)->toContain("#[MapName('this')]");
});

it('keys the validation rule by the original wire name `this`', function () {
    $code = generateThisHolder()->code;

    expect($code)->toContain("'this' => [");
});

it('compiles without a fatal (php -l), which TOKEN_PARSE alone cannot prove', function () {
    $code = generateThisHolder()->code;

    $file = tempnam(sys_get_temp_dir(), 'oal_this_').'.php';
    file_put_contents($file, $code);

    try {
        $output = [];
        $status = 0;
        exec('php -l '.escapeshellarg($file).' 2>&1', $output, $status);

        expect($status)->toBe(0, "php -l failed:\n".implode("\n", $output)."\n\n".$code);
    } finally {
        @unlink($file);
    }
});
