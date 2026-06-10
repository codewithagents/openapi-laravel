<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests;

use CodeWithAgents\OpenApiLaravel\OpenApiLaravelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            OpenApiLaravelServiceProvider::class,
        ];
    }
}
