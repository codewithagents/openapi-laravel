<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Generation settings, mirrored from the published openapi-laravel config.
 * Kept as a plain value object so the generator is testable without booting
 * a Laravel container.
 */
final readonly class GeneratorOptions
{
    /**
     * @param  array<string, true>|null  $keepSchemas  subset keep-set (issue #44), or null to emit every schema
     */
    public function __construct(
        public string $namespace = 'App\\Data',
        public string $dataSuffix = 'Data',
        public int $maxDepth = 64,
        /*
         * When true, a schema that declares `additionalProperties: false` emits a
         * rule rejecting any key outside its declared property set (a closed
         * object shape, issue #30). Off by default: enforcing a closed shape has
         * a forward-compatibility hazard (a producer adding a field would break a
         * consumer that has not regenerated), so strict rejection is opt in.
         */
        public bool $enforceClosedObjects = false,
        /*
         * Subset generation (issue #44). When non-null, the model generator emits
         * ONLY the named component schemas (the keys of this set), which the
         * caller has already closed over their transitive dependencies via
         * {@see SchemaClosure} so no `$ref` dangles. Null is the default and means
         * "emit every component schema", keeping the output byte-identical to a
         * run with no subset flags. A name => true membership set for O(1) lookup.
         *
         * @var array<string, true>|null
         */
        public ?array $keepSchemas = null,
    ) {}
}
