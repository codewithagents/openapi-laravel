<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use Illuminate\Console\Command;

/**
 * Builds a GenerationRequest from an artisan command: the same flag-over-config
 * resolution the generate command always used, lifted out so generate and check
 * read their inputs identically. Both commands accept the same options
 * (--spec, --output, --namespace, --controllers, --routes), so neither can
 * compute a different plan than the other.
 */
final readonly class CommandRequestFactory
{
    public function fromCommand(Command $command): GenerationRequest
    {
        $spec = $this->stringOption($command, 'spec') ?? $this->configString('openapi-laravel.spec');
        $output = $this->stringOption($command, 'output') ?? $this->configString('openapi-laravel.output.path');

        // A --namespace option overrides the configured output namespace; the
        // standalone binary already works this way, so the artisan commands
        // mirror it.
        $namespace = $this->stringOption($command, 'namespace')
            ?? $this->configString('openapi-laravel.output.namespace')
            ?? 'App\\Data';
        $suffix = $this->configString('openapi-laravel.output.suffix') ?? 'Data';

        $depth = config('openapi-laravel.max_depth');
        $maxDepth = is_int($depth) ? $depth : 64;
        $bytes = config('openapi-laravel.max_bytes');
        $maxBytes = is_int($bytes) ? $bytes : null;

        $controllers = (bool) $command->option('controllers') || (bool) config('openapi-laravel.controllers.enabled');
        $routes = (bool) $command->option('routes') || (bool) config('openapi-laravel.routes.enabled');

        $controllerPath = $this->configString('openapi-laravel.controllers.path');
        $controllerNamespace = $this->configString('openapi-laravel.controllers.namespace') ?? 'App\\Http\\Controllers\\Api';
        $routesPath = $this->configString('openapi-laravel.routes.path');

        return new GenerationRequest(
            $spec,
            $output,
            $namespace,
            $suffix,
            $maxDepth,
            $maxBytes,
            $controllers,
            $controllerPath,
            $controllerNamespace,
            $routes,
            $routesPath,
        );
    }

    private function stringOption(Command $command, string $name): ?string
    {
        $value = $command->option($name);

        return is_string($value) ? $value : null;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
