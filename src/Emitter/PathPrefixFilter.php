<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use cebe\openapi\spec\OpenApi;

/**
 * Path-prefix exclusion for subset generation (issue #96). Removes every path
 * whose literal spec path starts with one of the configured prefixes from the
 * parsed document, so the operations under it produce no controller method, no
 * route, and no query Data class, and never seed an --only-tags closure.
 *
 * The planner applies this BEFORE the subset closure is resolved and before the
 * operation collector runs, which is what makes generate and check agree: both
 * plan from the same filtered document.
 *
 * Matching is a plain, case-sensitive string-prefix test against the path as
 * written in the spec ("/api/v1/swagger" drops "/api/v1/swagger/pets" and also
 * "/api/v1/swaggerui"). Prefixes are trimmed, empties dropped, and duplicates
 * collapsed, so an empty or repeated entry can never silently drop everything
 * twice. With no prefixes the document is untouched and the output stays
 * byte-identical to a run without the flag.
 *
 * @internal
 */
final readonly class PathPrefixFilter
{
    /**
     * Remove every matching path from the document and report the prefixes
     * that matched nothing. A non-matching prefix is a likely typo (or a stale
     * flag after a spec change), but excluding nothing still leaves the output
     * complete, so the caller surfaces it as a warning, not an error.
     *
     * @param  list<string>  $prefixes  literal path prefixes to exclude
     * @return list<string> the cleaned prefixes that matched no path, in input order
     */
    public function apply(OpenApi $document, array $prefixes): array
    {
        $prefixes = $this->clean($prefixes);
        if ($prefixes === []) {
            return [];
        }

        $paths = $document->paths;
        if ($paths === null) {
            return $prefixes;
        }

        $matched = [];
        foreach (array_keys($paths->getPaths()) as $path) {
            $path = (string) $path;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $paths->removePath($path);
                    $matched[$prefix] = true;
                    // The path is gone; checking it against further prefixes
                    // could only re-remove it, so move to the next path.
                    break;
                }
            }
        }

        return array_values(array_filter(
            $prefixes,
            static fn (string $prefix): bool => ! isset($matched[$prefix]),
        ));
    }

    /**
     * Trim, drop empties, and collapse duplicates, preserving first-seen order
     * so diagnostics stay deterministic regardless of input order (the same
     * normalization SubsetSelection applies to tag and schema names).
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function clean(array $prefixes): array
    {
        $result = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if ($prefix === '' || in_array($prefix, $result, true)) {
                continue;
            }
            $result[] = $prefix;
        }

        return $result;
    }
}
