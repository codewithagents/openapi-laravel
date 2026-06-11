<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use Illuminate\Console\Command;

/**
 * Builds a GenerationRequest from an artisan command: the same flag-over-config
 * resolution the generate command always used, lifted out so generate and check
 * read their inputs identically. Both commands accept the same options
 * (--spec, --output, --namespace, --controllers/--no-controllers,
 * --routes/--no-routes), so neither can compute a different plan than the other.
 *
 * Toggle precedence is strict: an explicit flag beats the config value, the
 * config value beats the built-in default (enabled). Passing both the enable
 * and the disable flag is a configuration error, not a silent pick.
 *
 * @throws OptionException when --controllers/--no-controllers or
 *                         --routes/--no-routes are combined
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

        $controllers = $this->resolveToggle($command, 'controllers', 'openapi-laravel.controllers.enabled');
        $routes = $this->resolveToggle($command, 'routes', 'openapi-laravel.routes.enabled');

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

    /**
     * Resolves an enable/disable flag pair against the config: flag beats
     * config, config beats the built-in default (enabled). Both flags at once
     * is a contradiction the operator must resolve, so it fails loudly.
     *
     * @throws OptionException when both --{$flag} and --no-{$flag} are passed
     */
    private function resolveToggle(Command $command, string $flag, string $configKey): bool
    {
        $enable = (bool) $command->option($flag);
        $disable = (bool) $command->option('no-'.$flag);

        if ($enable && $disable) {
            throw new OptionException(sprintf('--%s and --no-%s cannot be combined. Pass at most one of them.', $flag, $flag));
        }

        if ($enable) {
            return true;
        }

        if ($disable) {
            return false;
        }

        $configured = config($configKey);

        return $configured === null ? true : (bool) $configured;
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
