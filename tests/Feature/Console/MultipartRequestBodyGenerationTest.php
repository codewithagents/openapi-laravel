<?php

declare(strict_types=1);

/**
 * Issue #75, command-level: the synthesized multipart request-body Data
 * classes are first-class generated output. `openapi:generate` writes them
 * next to the model Data classes with UploadedFile typing, and `openapi:check`
 * regenerates them through the same shared planner, so generate and check stay
 * in lockstep and a tampered body class registers as drift.
 */
$multipartSpec = function (): string {
    $spec = sys_get_temp_dir().'/oal_multipart_body_'.uniqid().'.json';
    file_put_contents($spec, json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Multipart body', 'version' => '1.0.0'],
        'paths' => [
            '/pets/upload' => [
                'post' => [
                    'tags' => ['Pet'],
                    'operationId' => 'uploadPetImage',
                    'requestBody' => [
                        'content' => ['multipart/form-data' => ['schema' => [
                            'type' => 'object',
                            'required' => ['image'],
                            'properties' => [
                                'image' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/png'],
                                'caption' => ['type' => 'string', 'maxLength' => 80],
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

$tempOut = fn (): string => sys_get_temp_dir().'/oal_multipart_body_out_'.uniqid();

it('writes the synthesized multipart body class with UploadedFile typing and file rules', function () use ($multipartSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $multipartSpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(is_file($out.'/UploadPetImageRequestData.php'))->toBeTrue();

    $code = (string) file_get_contents($out.'/UploadPetImageRequestData.php');
    expect($code)->toContain('use Illuminate\Http\UploadedFile;')
        ->and($code)->toContain('public readonly UploadedFile $image')
        ->and($code)->toContain("'image' => ['required', 'file', 'mimetypes:image/png']")
        ->and((string) file_get_contents($out.'/Http/AbstractPetController.php'))
        ->toContain('abstract public function uploadPetImage(UploadPetImageRequestData $body): JsonResponse;');
});

it('reports in sync right after generating (generate and check share the planner)', function () use ($multipartSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $multipartSpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    $this->artisan('openapi:check')
        ->expectsOutputToContain('Generated code is in sync with the spec.')
        ->assertExitCode(0);
});

it('registers a tampered multipart body class as drift', function () use ($multipartSpec, $tempOut) {
    $out = $tempOut();
    config()->set('openapi-laravel.spec', $multipartSpec());
    config()->set('openapi-laravel.output.path', $out);
    config()->set('openapi-laravel.controllers.path', $out.'/Http');
    config()->set('openapi-laravel.routes.path', $out.'/routes/api.generated.php');

    $this->artisan('openapi:generate')->assertSuccessful();

    $path = $out.'/UploadPetImageRequestData.php';
    file_put_contents($path, file_get_contents($path).' ');

    $this->artisan('openapi:check')
        ->expectsOutputToContain('[changed] '.$path)
        ->assertExitCode(1);
});
