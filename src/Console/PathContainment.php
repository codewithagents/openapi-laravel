<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * Containment guard for output paths that originate in a discovered config file
 * (issue #54).
 *
 * Threat model: `openapi-laravel.json` is auto-discovered from the working
 * directory by the standalone binary. Unlike a CLI flag, the operator never
 * typed those paths and may not know the file exists. A hostile config committed
 * to a cloned repository could redirect generated-file writes to arbitrary
 * locations via a `..` traversal, an absolute path, or a symlink whose target
 * escapes the project, the moment a developer runs the binary in that directory.
 * That is a path-traversal / arbitrary-write hazard.
 *
 * Policy: every path sourced from the config file must resolve to a location
 * inside an allowed root after normalization. The allowed root is the directory
 * the config file was discovered in (the working directory for the default
 * `openapi-laravel.json`, or the directory containing the file named by
 * `--config`). CLI flags keep full freedom by design, they are explicit operator
 * input, so this guard is only applied to config-file-sourced values.
 *
 * Normalization resolves `.` and `..` lexically, then resolves symlinks of the
 * longest existing path prefix (the target directory itself rarely exists yet,
 * so a purely lexical check would miss a symlinked parent). The check fails
 * CLOSED: any path that escapes the root, or any path that cannot be safely
 * resolved, is rejected with an actionable error before a single file is
 * written. Legitimate in-root relative and nested paths are unaffected.
 *
 * @internal
 */
final readonly class PathContainment
{
    /**
     * The absolute, symlink-resolved allowed root. Every contained path must
     * stay at or below this directory.
     */
    private string $root;

    /**
     * @param  string  $root  the directory the config file was discovered in
     */
    public function __construct(string $root)
    {
        $this->root = $this->canonicalizeExisting($root);
    }

    /**
     * Returns the path unchanged when it is contained within the root, or throws
     * with an actionable message naming the offending path and the reason.
     *
     * A null or empty path is passed through untouched: "not set in the config"
     * is the caller's concern, not this guard's. A value that is present is
     * always checked.
     *
     * @param  string  $label  the config key, used in the error message (e.g. "output.path")
     *
     * @throws OptionException when the resolved path escapes the allowed root
     */
    public function contain(?string $value, string $label, string $configPath): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $resolved = $this->resolve($value);

        if (! $this->isWithinRoot($resolved)) {
            throw new OptionException(sprintf(
                "Config file %s sets '%s' to '%s', which resolves to '%s', outside the project directory '%s'. "
                .'Paths from a discovered config file must stay inside the directory the config lives in (a config '
                .'file cannot redirect writes elsewhere via .., an absolute path, or a symlink). Move the target '
                .'inside the project, or pass the path explicitly as a CLI flag if you really mean to write outside it.',
                $configPath,
                $label,
                $value,
                $resolved,
                $this->root,
            ));
        }

        return $value;
    }

    /**
     * Resolves a config-relative path to an absolute, normalized form: a
     * relative value is anchored to the root, `.`/`..` segments are collapsed
     * lexically, and the longest existing prefix has its symlinks resolved so a
     * symlinked parent cannot smuggle the target outside the root.
     */
    private function resolve(string $value): string
    {
        $absolute = $this->isAbsolute($value) ? $value : $this->root.'/'.$value;
        $lexical = $this->normalizeSegments($absolute);

        return $this->resolveExistingPrefixSymlinks($lexical);
    }

    /**
     * Collapses `.` and `..` segments without touching the filesystem. A `..`
     * that would climb above the filesystem root is dropped (there is nothing
     * above `/`), which keeps the result absolute and lets the containment
     * comparison reject it cleanly rather than throwing here.
     */
    private function normalizeSegments(string $absolute): string
    {
        $segments = explode('/', $absolute);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($stack);

                continue;
            }

            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }

    /**
     * Walks the path from the root downward, resolving symlinks of each existing
     * component. As soon as a component does not exist, the remaining (lexically
     * normalized) tail is appended verbatim: it cannot be a symlink because it
     * does not exist yet. This catches a symlinked parent directory whose target
     * lives outside the root, the symlink-escape vector, while still allowing the
     * not-yet-created output directories.
     */
    private function resolveExistingPrefixSymlinks(string $lexical): string
    {
        $segments = array_values(array_filter(explode('/', $lexical), static fn (string $s): bool => $s !== ''));
        $current = '';

        foreach ($segments as $index => $segment) {
            $candidate = $current.'/'.$segment;

            // is_link() is checked before file_exists(): file_exists() follows
            // the link, so a DANGLING symlink reads as "does not exist" and would
            // be treated as a plain not-yet-created segment. A symlink, broken or
            // not, must go through realpath() so its true target is what gets
            // contained, never the link's own in-root location.
            if (is_link($candidate)) {
                $real = realpath($candidate);
                if ($real === false) {
                    // A broken or unresolvable symlink: fail closed by anchoring
                    // to a definitely-out-of-root sentinel so containment rejects it.
                    return "\0unresolvable:{$candidate}";
                }
                $current = $real;

                continue;
            }

            if (! file_exists($candidate)) {
                // Nothing below here exists, so nothing below here can be a
                // symlink. Append the rest verbatim and stop walking.
                $tail = array_slice($segments, $index);

                return ($current === '' ? '' : $current).'/'.implode('/', $tail);
            }

            $current = $candidate;
        }

        return $current === '' ? '/' : $current;
    }

    /**
     * Canonicalizes the allowed root. A relative root is anchored to the working
     * directory, then symlinks are resolved through the SAME existing-prefix
     * walk the target paths use. That shared resolution is what keeps the
     * containment comparison sound: on systems where a parent like /var is itself
     * a symlink (macOS /var -> /private/var), the root and every target resolve
     * that parent identically, so a legitimate in-root path is never wrongly
     * rejected for a /var vs /private/var mismatch.
     */
    private function canonicalizeExisting(string $root): string
    {
        $absolute = $this->isAbsolute($root) ? $root : (getcwd() ?: '/').'/'.$root;
        $lexical = $this->normalizeSegments($absolute);

        return $this->resolveExistingPrefixSymlinks($lexical);
    }

    private function isWithinRoot(string $resolved): bool
    {
        if ($resolved === $this->root) {
            return true;
        }

        return str_starts_with($resolved, $this->root.'/');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/');
    }
}
