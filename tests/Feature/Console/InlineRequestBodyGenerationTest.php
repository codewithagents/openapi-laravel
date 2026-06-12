<?php

declare(strict_types=1);

/**
 * Issue #76, command-level: the synthesized inline request-body Data classes
 * are first-class generated output. `openapi:generate` writes them next to
 * the model Data classes, and `openapi:check` regenerates them through the
 * same shared planner, so generate and check stay in lockstep and a tampered
 * or deleted body class registers as drift like any other owned file.
 */
$inlineBodySpec = function (): string {
    $spec = sys_get_temp_dir().'/oal_inline_body_'.uniqid().'.json';
    file_put_contents($spec, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Inline body', 'version' => '1.0.0'],
        'paths' => [
            '/pets' => [
                'post' => [
                    'tags' => ['Pet'],
                    'operationId' => 'createPet',
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => [
                                'name' => ['type' => 'string', 'maxLength' => 20],
                                'home' => [
                                    'type' => 'object',
                                    'properties' => ['city' => ['type' => 'string']],
                                ],
                            ],
                        ]]],
                    ],
                    'responses' => ['201' => ['description' => 'created']],
                ],
            ],
        ],
    ]));

    return $spec;
};

$tempOut = fn (): string => sys_get_temp_dir().'/oal_inline_body_out_'.uniqid();

it('writes the synthesized body class (and its nested class) next to the model Data classes', function () use ($inlineBodySpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $inlineBodySpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(is_file($out.'/Pet/CreatePetRequestData.php'))->toBeTrue()
        ->and(is_file($out.'/Pet/CreatePetRequestHomeData.php'))->toBeTrue()
        ->and((string) file_get_contents($out.'/Http/AbstractPetController.php'))
        ->toContain('abstract public function createPet(CreatePetRequestData $body): JsonResponse;');
});

it('reports in sync right after generating (generate and check share the planner)', function () use ($inlineBodySpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $inlineBodySpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    $this->artisan('openapi:check')
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);
});

it('registers a tampered body class as drift', function () use ($inlineBodySpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $inlineBodySpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    $path = $out.'/Pet/CreatePetRequestData.php';
    file_put_contents($path, file_get_contents($path).' ');

    $this->artisan('openapi:check')
        ->expectsOutputToContain('[changed] '.$path)
        ->assertExitCode(1);
});

it('registers a deleted body class as missing drift', function () use ($inlineBodySpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $inlineBodySpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    $path = $out.'/Pet/CreatePetRequestData.php';
    unlink($path);

    $this->artisan('openapi:check')
        ->expectsOutputToContain('[missing] '.$path)
        ->assertExitCode(1);
});
