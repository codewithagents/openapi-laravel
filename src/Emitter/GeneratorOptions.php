<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Generation settings, mirrored from the published openapi-laravel config.
 * Kept as a plain value object so the generator is testable without booting
 * a Laravel container.
 *
 * @internal
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
         * object shape, issue #30). On by default: the spec is the source of
         * truth, so a schema that explicitly closed its shape gets that shape
         * enforced. A consumer that needs lenient, forward-compatible output
         * during contract evolution opts out via --no-enforce-closed-objects.
         */
        public bool $enforceClosedObjects = true,
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
        /*
         * Validation extension trait (issue #83). When set to the FQCN of a
         * user-owned trait, every generated Data class (models, discriminated
         * union bases and variants, per-operation query classes) carries a
         * `use <Trait>;` line. laravel-data discovers validation hooks through
         * method_exists(), so static messages() / attributes() methods on the
         * trait customize validation messages and attribute names WITHOUT
         * editing the generated classes, which regeneration overwrites. Null
         * (the default) keeps the output byte-identical to before.
         */
        public ?string $validationTrait = null,
    ) {}

    /**
     * The namespace the inlined runtime support classes are emitted into (issue
     * #40). It mirrors the Data namespace with a `\Support` suffix (Data at
     * `App\Data` -> support at `App\Data\Support`), so generated code imports its
     * rules and transformer from the consumer's own namespace and carries no
     * runtime dependency on the generator package. Derived, never configured
     * separately, so it always tracks the Data namespace.
     */
    public function supportNamespace(): string
    {
        return $this->namespace.'\\Support';
    }
}
