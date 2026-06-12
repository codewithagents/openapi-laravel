<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * The outcome of resolving a {@see SubsetSelection} through {@see SchemaClosure}:
 * the exact set of component schemas to emit (the seeds plus their transitive
 * dependency closure), the path+method operation keys to keep when generating
 * the server scaffold, and the tags/schemas the operator named that matched
 * nothing in the spec.
 *
 * Both name lists are sorted for determinism. The operation keys are
 * "METHOD path" (upper-case method, e.g. "GET /pets"), the same shape
 * {@see SchemaClosure} keys them by, so a server emitter can test membership
 * cheaply.
 *
 * @internal
 */
final readonly class ResolvedClosure
{
    /**
     * @param  list<string>  $schemas  component-schema names to emit (closed set), sorted
     * @param  list<string>  $operationKeys  "METHOD path" keys of kept operations, sorted
     * @param  list<string>  $unknownTags  selected tags that matched no operation
     * @param  list<string>  $unknownSchemas  selected schema names absent from the spec
     */
    public function __construct(
        public array $schemas,
        public array $operationKeys,
        public array $unknownTags,
        public array $unknownSchemas,
    ) {}

    /**
     * Whether any selected tag or schema matched nothing. The caller treats this
     * as a hard error (an explicit, typo-catching failure) rather than silently
     * emitting an empty or partial slice.
     */
    public function hasUnknown(): bool
    {
        return $this->unknownTags !== [] || $this->unknownSchemas !== [];
    }

    /**
     * Whether a given path+method operation survives the subset. Used by the
     * server scaffold to keep only the selected operations' controllers/routes.
     */
    public function keepsOperation(string $method, string $path): bool
    {
        return in_array(strtoupper($method).' '.$path, $this->operationKeys, true);
    }

    /**
     * The kept schemas as a fast membership set (name => true), for the model
     * generator to filter its component map without an O(n) scan per lookup.
     *
     * @return array<string, true>
     */
    public function schemaSet(): array
    {
        return array_fill_keys($this->schemas, true);
    }
}
