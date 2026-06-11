<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * The operator's requested generation subset: a set of tag names and a set of
 * component-schema names (issue #44). An empty selection (no tags, no schemas)
 * is the sentinel for "generate everything", which is the default and keeps the
 * output byte-identical to a run with no subset flags at all.
 *
 * This is a plain value object describing INTENT. Turning the intent into the
 * concrete set of schemas and operations to emit (the transitive dependency
 * closure) is {@see SchemaClosure}'s job; this object only carries the raw,
 * de-duplicated, order-stable request so the generator and the planner can be
 * told what the operator asked for.
 */
final readonly class SubsetSelection
{
    /**
     * @param  list<string>  $tags  selected tag names (operations carrying any of these are kept)
     * @param  list<string>  $schemas  selected component-schema names (these plus their closure are kept)
     */
    private function __construct(
        public array $tags,
        public array $schemas,
    ) {}

    /**
     * The "no subset" sentinel: nothing selected, so the generator emits the full
     * spec exactly as it does today. {@see isAll()} is true for this instance.
     */
    public static function all(): self
    {
        return new self([], []);
    }

    /**
     * Build a selection from raw tag and schema name lists. Empty strings are
     * dropped and duplicates collapsed, preserving first-seen order so the
     * closure and any diagnostics stay deterministic regardless of input order.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $schemas
     */
    public static function of(array $tags, array $schemas): self
    {
        $tags = self::clean($tags);
        $schemas = self::clean($schemas);

        return new self($tags, $schemas);
    }

    /**
     * Whether this is the "generate everything" selection (no tags and no
     * schemas requested). When true, callers must not filter at all, so the
     * default output is identical to the pre-subset behavior.
     */
    public function isAll(): bool
    {
        return $this->tags === [] && $this->schemas === [];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }

        return $result;
    }
}
