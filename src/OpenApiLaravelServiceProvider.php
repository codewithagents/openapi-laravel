<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel;

use CodeWithAgents\OpenApiLaravel\Console\CheckCommand;
use CodeWithAgents\OpenApiLaravel\Console\GenerateCommand;
use Illuminate\Support\ServiceProvider;

final class OpenApiLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/openapi-laravel.php', 'openapi-laravel');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/openapi-laravel.php' => config_path('openapi-laravel.php'),
            ], 'openapi-laravel-config');

            $this->commands([
                GenerateCommand::class,
                CheckCommand::class,
            ]);
        }
    }
}
