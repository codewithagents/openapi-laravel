<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

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
 * twice. With no prefixes the document is returned untouched and the output
 * stays byte-identical to a run without the flag.
 *
 * The document graph is read-only (issue #104), so filtering produces a NEW
 * document sharing every untouched node rather than mutating paths in place.
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
     * @return array{0: OpenApiDocument, 1: list<string>} the filtered document
     *                                                    (the input document when nothing matched) and the cleaned
     *                                                    prefixes that matched no path, in input order
     */
    public function apply(OpenApiDocument $document, array $prefixes): array
    {
        $prefixes = $this->clean($prefixes);
        if ($prefixes === []) {
            return [$document, []];
        }

        $matched = [];
        $kept = [];
        foreach ($document->paths as $path => $pathItem) {
            $path = (string) $path;
            $excluded = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $matched[$prefix] = true;
                    $excluded = true;
                    // The path is gone; checking it against further prefixes
                    // could only re-remove it, so move to the next path.
                    break;
                }
            }
            if (! $excluded) {
                $kept[$path] = $pathItem;
            }
        }

        $unmatched = array_values(array_filter(
            $prefixes,
            static fn (string $prefix): bool => ! isset($matched[$prefix]),
        ));

        if ($matched === []) {
            return [$document, $unmatched];
        }

        return [new OpenApiDocument(
            openapi: $document->openapi,
            info: $document->info,
            paths: $kept,
            components: $document->components,
            webhooks: $document->webhooks,
            security: $document->security,
            tags: $document->tags,
            servers: $document->servers,
            warnings: $document->warnings,
            extensions: $document->extensions,
        ), $unmatched];
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
