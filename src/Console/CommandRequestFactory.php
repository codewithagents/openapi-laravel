<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use Illuminate\Console\Command;

/**
 * Builds a GenerationRequest from an artisan command: the same flag-over-config
 * resolution the generate command always used, lifted out so generate and check
 * read their inputs identically. Both commands accept the same options
 * (--spec, --output, --namespace, --controllers/--no-controllers,
 * --routes/--no-routes, --enforce-closed-objects/--no-enforce-closed-objects),
 * so neither can compute a different plan than the other.
 *
 * Toggle precedence is strict: an explicit flag beats the config value, the
 * config value beats the built-in default (enabled). Passing both the enable
 * and the disable flag is a configuration error, not a silent pick.
 *
 * @throws OptionException when --controllers/--no-controllers,
 *                         --routes/--no-routes, or
 *                         --enforce-closed-objects/--no-enforce-closed-objects
 *                         are combined
 *
 * @internal
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

        // Closed-object enforcement is now a default-on toggle, resolved with the
        // same strict precedence as controllers/routes: an explicit flag beats
        // the config key, the config key beats the built-in default (enabled).
        // Passing both --enforce-closed-objects and --no-enforce-closed-objects
        // is a contradiction that fails loudly (exit 2).
        $enforceClosedObjects = $this->resolveToggle($command, 'enforce-closed-objects', 'openapi-laravel.enforce_closed_objects');

        $controllerPath = $this->configString('openapi-laravel.controllers.path');
        $controllerNamespace = $this->configString('openapi-laravel.controllers.namespace') ?? 'App\\Http\\Controllers\\Api';
        $routesPath = $this->configString('openapi-laravel.routes.path');

        // Route group settings (issue #71). Config-only, no CLI flags: a list
        // of middleware names and/or a URI prefix wrap the generated routes in
        // a Route::group block. Middleware names are NEVER comma-split, because
        // a middleware parameter list legitimately contains commas
        // (throttle:60,1), so the key must be a real array of strings.
        $routesMiddleware = $this->configStringList('openapi-laravel.routes.middleware');
        $routesPrefix = $this->normalizePrefix($this->configString('openapi-laravel.routes.prefix'));

        // Subset generation (issue #44): a flag (comma-separated) overrides the
        // config key (a comma string or a list); an empty result means "full
        // spec". The closure is resolved later in the planner.
        $onlyTags = $this->resolveList($command, 'only-tags', 'openapi-laravel.only_tags');
        $onlySchemas = $this->resolveList($command, 'only-schemas', 'openapi-laravel.only_schemas');

        // Path-prefix exclusion (issue #96): the repeatable flag (when any
        // occurrence is given) wins over the config key, a list of strings.
        // Entries are NEVER comma-split, because a literal URL path may
        // contain a comma; pass the flag once per prefix instead. Empty means
        // "exclude nothing".
        $excludePathPrefixes = $this->resolveRepeatable($command, 'exclude-path-prefix', 'openapi-laravel.exclude_path_prefixes');

        // Security-to-middleware mapping (issue #77). Config-only like the
        // route group settings: a map is config-shaped, there is no CLI flag.
        $securityMiddlewareMap = $this->configMiddlewareMap('openapi-laravel.security.middleware_map');

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
            $routesMiddleware,
            $routesPrefix,
            $excludePathPrefixes,
            $securityMiddlewareMap,
        );
    }

    /**
     * Read the security.middleware_map config key (issue #77): a map of
     * security scheme name => middleware name(s). A string value is one
     * middleware; a list value is several (each entry its own string, never
     * comma-split, so 'throttle:60,1' stays intact); an empty string or list
     * keeps the scheme mapped to nothing, the documented "handled elsewhere"
     * acknowledgment. Entries of any other shape are dropped, mirroring the
     * lenient reads of the other PHP-config keys.
     *
     * @return array<string, list<string>>
     */
    private function configMiddlewareMap(string $key): array
    {
        $configured = config($key);
        if (! is_array($configured)) {
            return [];
        }

        $map = [];
        foreach ($configured as $scheme => $value) {
            $scheme = trim((string) $scheme);
            if ($scheme === '') {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                $map[$scheme] = $value === '' ? [] : [$value];

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $names = [];
            foreach ($value as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
            $map[$scheme] = $names;
        }

        return $map;
    }

    /**
     * Resolve a repeatable array flag against its config key: when the flag was
     * passed at least once with a non-empty value, the flag occurrences win;
     * otherwise the config key (a list of strings) is read. Entries are trimmed
     * and never comma-split. Returns [] when neither is set.
     *
     * @return list<string>
     */
    private function resolveRepeatable(Command $command, string $flag, string $configKey): array
    {
        $flagValue = $command->option($flag);
        if (is_array($flagValue)) {
            $values = [];
            foreach ($flagValue as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $values[] = trim($value);
                }
            }

            if ($values !== []) {
                return $values;
            }
        }

        return $this->configStringList($configKey);
    }

    /**
     * Read a config key as a list of strings (middleware names, path
     * prefixes): trimmed, non-empty, in order. Anything that is not an array
     * (or an empty one) resolves to [], the "not set" value. Entries are not
     * comma-split.
     *
     * @return list<string>
     */
    private function configStringList(string $key): array
    {
        $configured = config($key);
        if (! is_array($configured)) {
            return [];
        }

        $names = [];
        foreach ($configured as $value) {
            if (is_string($value) && trim($value) !== '') {
                $names[] = trim($value);
            }
        }

        return $names;
    }

    /**
     * An empty or whitespace-only prefix means "no prefix", so it normalizes
     * to null and can never emit a useless group wrapper.
     */
    private function normalizePrefix(?string $prefix): ?string
    {
        if ($prefix === null) {
            return null;
        }

        $prefix = trim($prefix);

        return $prefix === '' ? null : $prefix;
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

        return $configured === null || (bool) $configured;
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
