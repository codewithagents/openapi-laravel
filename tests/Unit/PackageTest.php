<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\OpenApiLaravelServiceProvider;

it('autoloads the package namespace', function () {
    expect(class_exists(OpenApiLaravelServiceProvider::class))->toBeTrue();
});
