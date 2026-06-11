<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * Loads the standalone binary's JSON config file. By default the binary looks
 * for `openapi-laravel.json` in the working directory; `--config=<path>` points
 * it elsewhere. The file is operator input but still untrusted in shape, so it
 * is validated strictly before anything is generated: malformed JSON, a
 * non-object document, an unknown key, or a wrong value type each fail with a
 * clear message and no file is written. Namespaces and identifiers from the
 * file flow into the same OptionValidator checks the flags go through.
 */
final readonly class StandaloneConfigLoader
{
    private const DEFAULT_FILENAME = 'openapi-laravel.json';

    /**
     * Allowed keys per section. A null value means "scalar key", an array
     * value lists the allowed sub-keys of an object section.
     */
    private const SCHEMA = [
        'spec' => null,
        'output' => ['path', 'namespace', 'suffix', 'prune'],
        'controllers' => ['enabled', 'path', 'namespace'],
        'routes' => ['enabled', 'path'],
        'max_depth' => null,
        'max_bytes' => null,
    ];

    /**
     * @param  ?string  $explicitPath  the --config flag value, or null to discover
     * @param  string  $cwd  directory searched for the default file name
     *
     * @throws OptionException on a missing explicit file, malformed JSON, an unknown key, or a wrong value type
     */
    public function load(?string $explicitPath, string $cwd): StandaloneConfig
    {
        $path = $explicitPath ?? rtrim($cwd, '/').'/'.self::DEFAULT_FILENAME;

        if (! is_file($path)) {
            if ($explicitPath !== null) {
                throw new OptionException("Config file not found: {$explicitPath}");
            }

            return new StandaloneConfig;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new OptionException("Config file could not be read: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            $reason = json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'expected a JSON object';
            throw new OptionException("Malformed config file {$path}: {$reason}.");
        }

        $this->rejectUnknownKeys($decoded, $path);

        return new StandaloneConfig(
            spec: $this->string($decoded, 'spec', $path),
            outputPath: $this->string($decoded['output'] ?? [], 'path', $path, 'output.'),
            namespace: $this->string($decoded['output'] ?? [], 'namespace', $path, 'output.'),
            suffix: $this->string($decoded['output'] ?? [], 'suffix', $path, 'output.'),
            prune: $this->bool($decoded['output'] ?? [], 'prune', $path, 'output.'),
            controllersEnabled: $this->bool($decoded['controllers'] ?? [], 'enabled', $path, 'controllers.'),
            controllerPath: $this->string($decoded['controllers'] ?? [], 'path', $path, 'controllers.'),
            controllerNamespace: $this->string($decoded['controllers'] ?? [], 'namespace', $path, 'controllers.'),
            routesEnabled: $this->bool($decoded['routes'] ?? [], 'enabled', $path, 'routes.'),
            routesPath: $this->string($decoded['routes'] ?? [], 'path', $path, 'routes.'),
            maxDepth: $this->int($decoded, 'max_depth', $path),
            maxBytes: $this->int($decoded, 'max_bytes', $path),
        );
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     *
     * @throws OptionException
     */
    private function rejectUnknownKeys(array $decoded, string $path): void
    {
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, self::SCHEMA)) {
                throw new OptionException("Unknown key '{$key}' in config file {$path}. Allowed keys: ".implode(', ', array_keys(self::SCHEMA)).'.');
            }

            $subKeys = self::SCHEMA[$key];
            if ($subKeys === null) {
                continue;
            }

            if (! is_array($value) || array_is_list($value)) {
                throw new OptionException("Invalid '{$key}' in config file {$path}: expected a JSON object.");
            }

            foreach (array_keys($value) as $subKey) {
                if (! is_string($subKey) || ! in_array($subKey, $subKeys, true)) {
                    $label = is_string($subKey) ? $subKey : (string) $subKey;
                    throw new OptionException("Unknown key '{$key}.{$label}' in config file {$path}. Allowed keys: ".implode(', ', array_map(static fn (string $s): string => $key.'.'.$s, $subKeys)).'.');
                }
            }
        }
    }

    /**
     * @throws OptionException when the key is present but not a string
     */
    private function string(mixed $section, string $key, string $path, string $prefix = ''): ?string
    {
        $value = is_array($section) ? ($section[$key] ?? null) : null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new OptionException("Invalid '{$prefix}{$key}' in config file {$path}: expected a string.");
        }

        return $value;
    }

    /**
     * @throws OptionException when the key is present but not a boolean
     */
    private function bool(mixed $section, string $key, string $path, string $prefix = ''): ?bool
    {
        $value = is_array($section) ? ($section[$key] ?? null) : null;
        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            throw new OptionException("Invalid '{$prefix}{$key}' in config file {$path}: expected true or false.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $section
     *
     * @throws OptionException when the key is present but not an integer
     */
    private function int(array $section, string $key, string $path): ?int
    {
        $value = $section[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new OptionException("Invalid '{$key}' in config file {$path}: expected an integer.");
        }

        return $value;
    }
}
