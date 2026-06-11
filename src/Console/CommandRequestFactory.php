<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use Illuminate\Console\Command;

/**
 * Builds a GenerationRequest from an artisan command: the same flag-over-config
 * resolution the generate command always used, lifted out so generate and check
 * read their inputs identically. Both commands accept the same options
 * (--spec, --output, --namespace, --controllers/--no-controllers,
 * --routes/--no-routes, --enforce-closed-objects), so neither can compute a
 * different plan than the other.
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

        // Closed-object enforcement is a plain opt-in (default OFF, unlike the
        // default-on controllers/routes toggles): the flag or the config key
        // turns it on, and there is no disable flag to reconcile.
        $enforceClosedObjects = (bool) $command->option('enforce-closed-objects')
            || (bool) config('openapi-laravel.enforce_closed_objects');

        $controllerPath = $this->configString('openapi-laravel.controllers.path');
        $controllerNamespace = $this->configString('openapi-laravel.controllers.namespace') ?? 'App\\Http\\Controllers\\Api';
        $routesPath = $this->configString('openapi-laravel.routes.path');

        // Subset generation (issue #44): a flag (comma-separated) overrides the
        // config key (a comma string or a list); an empty result means "full
        // spec". The closure is resolved later in the planner.
        $onlyTags = $this->resolveList($command, 'only-tags', 'openapi-laravel.only_tags');
        $onlySchemas = $this->resolveList($command, 'only-schemas', 'openapi-laravel.only_schemas');

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
            $enforceClosedObjects,
            $onlyTags,
            $onlySchemas,
        );
    }

    /**
     * Resolve a comma-separated subset flag against its config key: the flag
     * (when present) wins, otherwise the config value is read. The config value
     * may be a comma-separated string or a list of strings; either is normalized
     * to a clean list of names (trimmed, empties dropped). Returns [] when
     * neither is set, which the planner reads as "no subset".
     *
     * @return list<string>
     */
    private function resolveList(Command $command, string $flag, string $configKey): array
    {
        $flagValue = $command->option($flag);
        if (is_string($flagValue) && trim($flagValue) !== '') {
            return $this->splitList($flagValue);
        }

        $configured = config($configKey);
        if (is_string($configured)) {
            return $this->splitList($configured);
        }

        if (is_array($configured)) {
            $names = [];
            foreach ($configured as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $names[] = trim($value);
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * Split a comma-separated list into trimmed, non-empty names.
     *
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        $names = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $names[] = $part;
            }
        }

        return $names;
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
