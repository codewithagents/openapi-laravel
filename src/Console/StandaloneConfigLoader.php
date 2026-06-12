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
 *
 * Output paths get a second, stricter guard (issue #54). cwd discovery means a
 * hostile config committed to a cloned repository could silently redirect
 * generated-file writes the moment a developer runs the binary in that
 * directory, paths the operator never typed. So every write path sourced from
 * the file (output.path, controllers.path, routes.path) is contained to the
 * directory the config lives in via PathContainment: a `..` traversal, an
 * absolute escape, or a symlinked-parent escape fails closed before anything is
 * written. CLI flags keep full freedom by design (explicit operator input), so
 * the containment is applied here, not in the shared request builder.
 *
 * @internal
 */
final readonly class StandaloneConfigLoader
{
    private const DEFAULT_FILENAME = 'openapi-laravel.json';

    /**
     * Maximum config file size accepted before reading (1 MiB). A real config
     * file is a few hundred bytes; the guard mirrors the max_bytes posture on
     * the spec input and caps the blast radius of a hostile or accidental
     * multi-gigabyte file before file_get_contents() loads it into memory.
     */
    private const MAX_BYTES = 1_048_576;

    /**
     * Allowed keys per section. A null value means "scalar key", an array
     * value lists the allowed sub-keys of an object section.
     */
    private const SCHEMA = [
        'spec' => null,
        'output' => ['path', 'namespace', 'suffix', 'prune'],
        'controllers' => ['enabled', 'path', 'namespace'],
        'routes' => ['enabled', 'path', 'middleware', 'prefix'],
        'enforce_closed_objects' => null,
        'max_depth' => null,
        'max_bytes' => null,
        'only_tags' => null,
        'only_schemas' => null,
    ];

    /**
     * @param  ?string  $explicitPath  the --config flag value, or null to discover
     * @param  string  $cwd  directory searched for the default file name
     *
     * @throws OptionException on a missing explicit file, an oversized file, malformed JSON, an unknown key, or a wrong value type
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

        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            $limit = self::MAX_BYTES;
            throw new OptionException("Config file {$path} exceeds the {$limit} byte limit (or its size could not be determined). A config file should be a few hundred bytes; nothing was generated.");
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

        // The allowed root for config-sourced write paths is the directory the
        // config file lives in: the working directory for the discovered default
        // file, or the --config file's own directory. Every write path is
        // contained to it before it can reach the writer (issue #54).
        $containment = new PathContainment(dirname($path));

        return new StandaloneConfig(
            spec: $this->string($decoded, 'spec', $path),
            outputPath: $containment->contain($this->string($decoded['output'] ?? [], 'path', $path, 'output.'), 'output.path', $path),
            namespace: $this->string($decoded['output'] ?? [], 'namespace', $path, 'output.'),
            suffix: $this->string($decoded['output'] ?? [], 'suffix', $path, 'output.'),
            prune: $this->bool($decoded['output'] ?? [], 'prune', $path, 'output.'),
            controllersEnabled: $this->bool($decoded['controllers'] ?? [], 'enabled', $path, 'controllers.'),
            controllerPath: $containment->contain($this->string($decoded['controllers'] ?? [], 'path', $path, 'controllers.'), 'controllers.path', $path),
            controllerNamespace: $this->string($decoded['controllers'] ?? [], 'namespace', $path, 'controllers.'),
            routesEnabled: $this->bool($decoded['routes'] ?? [], 'enabled', $path, 'routes.'),
            routesPath: $containment->contain($this->string($decoded['routes'] ?? [], 'path', $path, 'routes.'), 'routes.path', $path),
            routesMiddleware: $this->middlewareList($decoded['routes'] ?? [], $path),
            routesPrefix: $this->string($decoded['routes'] ?? [], 'prefix', $path, 'routes.'),
            enforceClosedObjectsEnabled: $this->bool($decoded, 'enforce_closed_objects', $path),
            maxDepth: $this->int($decoded, 'max_depth', $path),
            maxBytes: $this->int($decoded, 'max_bytes', $path),
            onlyTags: $this->stringList($decoded, 'only_tags', $path),
            onlySchemas: $this->stringList($decoded, 'only_schemas', $path),
        );
    }

    /**
     * Read a subset key (issue #44) as a list of names. Accepts a comma-separated
     * string or a JSON array of strings; either is normalized to a clean list
     * (trimmed, non-empty). Returns null when the key is absent so the binary
     * applies its "no subset" default. A non-string, non-array value, or an array
     * holding a non-string element, fails with a clear message.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<string>|null
     *
     * @throws OptionException when the key is present but not a string or string list
     */
    private function stringList(array $decoded, string $key, string $path): ?array
    {
        $value = $decoded[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $names = [];
            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $names[] = $part;
                }
            }

            return $names;
        }

        if (is_array($value) && array_is_list($value)) {
            $names = [];
            foreach ($value as $element) {
                if (! is_string($element)) {
                    throw new OptionException("Invalid '{$key}' in config file {$path}: every entry must be a string.");
                }
                $element = trim($element);
                if ($element !== '') {
                    $names[] = $element;
                }
            }

            return $names;
        }

        throw new OptionException("Invalid '{$key}' in config file {$path}: expected a comma-separated string or a list of strings.");
    }

    /**
     * Read routes.middleware (issue #71) as a JSON list of middleware names,
     * trimmed and non-empty. Deliberately NOT comma-split (unlike only_tags /
     * only_schemas): a middleware parameter list legitimately contains commas,
     * e.g. "throttle:60,1", so a comma-separated string form would corrupt it.
     * Returns null when the key is absent (no group).
     *
     * @param  mixed  $section  the decoded `routes` section
     * @return list<string>|null
     *
     * @throws OptionException when the key is present but not a list of strings
     */
    private function middlewareList(mixed $section, string $path): ?array
    {
        $value = is_array($section) ? ($section['middleware'] ?? null) : null;
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new OptionException("Invalid 'routes.middleware' in config file {$path}: expected a list of strings (middleware names are never comma-split, pass each as its own entry).");
        }

        $names = [];
        foreach ($value as $element) {
            if (! is_string($element)) {
                throw new OptionException("Invalid 'routes.middleware' in config file {$path}: every entry must be a string.");
            }
            $element = trim($element);
            if ($element !== '') {
                $names[] = $element;
            }
        }

        return $names;
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
