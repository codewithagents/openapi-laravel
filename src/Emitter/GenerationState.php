<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * The mutable per-run state of one ModelGenerator generation (issue #109).
 *
 * generate() previously reset ~19 instance fields by hand; a fresh
 * GenerationState per run replaces that ritual and gives the extracted
 * collaborators (type resolver, rules builder, renderers, request synthesis)
 * one shared context object instead of a back-reference to the god class.
 *
 * The component registry, the alias caches, the emitted file buckets, the
 * diagnostics channel, and the grouped-layout bookkeeping all live here, plus
 * the cross-cutting queries that only need this state (reference lookups,
 * scope tracking, namespace resolution).
 *
 * @internal
 */
final class GenerationState
{
    /**
     * Discriminated-union registry for the current generate() run: which
     * component schemas are union bases (oneOf/anyOf + discriminator over object
     * members) and which are their variants. Drives the abstract base + variant
     * emission and the `$ref`-to-base typing. Assigned by generate() once the
     * component map is known.
     */
    public DiscriminatorRegistry $discriminators;

    /**
     * Component schema name => [className, kind, schema].
     *
     * @var array<string, array{class: string, kind: 'data'|'enum', schema: SchemaNode}>
     */
    public array $registry = [];

    /**
     * Component schema name => writable variant class name, for schemas that
     * split into read/write variants. Used by the public registry() accessor.
     *
     * @var array<string, string>
     */
    public array $writeClasses = [];

    /**
     * Component schema name => resolved array type, for pure-map components
     * (`type: object` with only `additionalProperties`, no named properties).
     * Such a component is not emitted as its own Data class: a `$ref` to it
     * inlines the typed array (`array<string, int>`) at the use site instead of
     * pointing at an empty class.
     *
     * @var array<string, ResolvedType>
     */
    public array $mapAliases = [];

    /**
     * Component schema name => the original SchemaNode, for pure-map components.
     * Kept separately from $registry (which holds emitted Data/enum classes)
     * so a $ref to a pure-map component can recover its value schema for rules.
     *
     * @var array<string, SchemaNode>
     */
    public array $mapSchemas = [];

    /**
     * Component schema name => resolved underlying type, for non-object alias
     * components: a top-level component that is itself a scalar (`{type: string,
     * format: date-time}`), an array, or a oneOf/anyOf union, with no object
     * `properties`. Such a component is a TYPE ALIAS, not a Data class: a `$ref`
     * to it resolves to the underlying type at the use site instead of pointing
     * at an empty class (which would silently fail to hydrate). Mirrors
     * $mapAliases.
     *
     * @var array<string, ResolvedType>
     */
    public array $aliasTypes = [];

    /**
     * Component schema name => the original SchemaNode, for non-object alias
     * components. Kept separately so a `$ref` to such an alias can recover the
     * underlying schema and reuse rule derivation at the use site (so a
     * date-time alias still contributes its date-time rule, a length-bounded
     * string alias its max:/min:, etc.). Mirrors $mapSchemas.
     *
     * @var array<string, SchemaNode>
     */
    public array $aliasSchemas = [];

    /**
     * @var array<string, GeneratedFile>
     */
    public array $files = [];

    /**
     * Per-operation query Data classes (issue #63), emitted on demand AFTER
     * generate() ran, keyed by class name. Kept apart from $files because
     * generate() already returned its file set when the server scaffold asks
     * for query classes; the planner collects these separately.
     *
     * @var array<string, GeneratedFile>
     */
    public array $queryFiles = [];

    /**
     * Per-operation request-body Data classes (issue #76), emitted on demand
     * AFTER generate() ran, keyed by class name. The bucket also holds every
     * nested class an inline body spawned (a nested object property becomes
     * its own Data class, exactly like a component's). Kept apart from $files
     * for the same reason as $queryFiles.
     *
     * @var array<string, GeneratedFile>
     */
    public array $bodyFiles = [];

    /**
     * Non-fatal diagnostics gathered during a generate() run, keyed by the
     * warning text so the same finding (re-seen across the read/write variants of
     * one schema, or a recursive inline emit) is recorded only once. The CLI
     * surfaces these verbatim. They never change what is generated: the channel
     * only reports silent information loss the spec forced on us.
     *
     * @var array<string, true>
     */
    public array $warnings = [];

    /**
     * Where the resolver currently is, leading every degradation warning
     * (issue #67): 'Schema "Holder"' while a component (or its read/write
     * variants and inline nested classes) is emitted, 'Query parameters of
     * operation GET /pets' during query-class generation. Deliberately
     * schema-grained, not property-grained: combined with the text-keyed
     * dedupe above, the read and write variants of one schema report a shared
     * degradation once instead of spamming the channel per generated class.
     */
    public string $warningContext = 'Document';

    /**
     * Whether the class currently being emitted is the ROOT of a
     * multipart/form-data request body (issue #75). Only the root properties
     * of such a body are form parts, so only there a `type: string,
     * format: binary` property (or an array of them) becomes an UploadedFile
     * with `file` rules; a binary string nested deeper sits inside a
     * JSON-serialized part and keeps its plain string typing. Set around the
     * multipart emitData() call and consulted together with `depth === 0`,
     * mirroring the $warningContext pattern.
     */
    public bool $multipartBody = false;

    /**
     * Runtime support classes the current generate() run actually referenced
     * (issue #40), keyed by short name so each is recorded once. Drives the
     * inlined Support file set: only the support classes a spec uses are emitted
     * into the consumer's Support namespace, so the set stays minimal and
     * deterministic.
     *
     * @var array<string, true>
     */
    public array $usedSupportClasses = [];

    /**
     * Emitted class name => its tag group under the grouped data layout
     * (issue #93), or null for the flat root. Holds EVERY generated class
     * (components, write variants, nested inline classes, synthesized union
     * variants, per-operation query and body classes). Drives the per-file
     * namespace, the subdirectory, and the cross-group import decisions.
     *
     * @var array<string, ?string>
     */
    public array $fileGroups = [];

    /**
     * The tag-grouped layout attribution for the current generate() run
     * (issue #93): schema name => owning group. Computed from the document
     * itself unless the options injected a precomputed map (a test seam).
     *
     * @var array<string, string>
     */
    public array $schemaGroups = [];

    /**
     * Reference scopes for the class currently being emitted (issue #93): a
     * stack because emission is reentrant (an inline object property spawns a
     * nested class mid-emit). Each entry records the tag group the emission
     * runs under (so a spawned nested class inherits it) and the generated
     * classes the rendered code references (property types, DataCollectionOf
     * targets, Rule::enum classes, morph arms, the variant base), so the
     * renderer can import exactly the cross-group references. The alias and
     * map classification passes push a group-only scope, so a nested class
     * spawned from inside an alias/map component follows THAT component.
     *
     * @var list<array{group: ?string, refs: array<string, true>}>
     */
    private array $refScopes = [];

    public function __construct(
        public readonly GeneratorOptions $options,
        /*
         * Allocates the generated CLASS short-names (Data classes, enums,
         * writable variants, query/request classes, discriminator variants).
         * Constructed case-insensitive (issue #108): two schemas whose class
         * names differ only by case (e.g. `HttpHealthCheck` / `HTTPHealthCheck`)
         * would otherwise emit two `.php` files that collide on a
         * case-insensitive filesystem, the second silently clobbering the
         * first. The property/parameter/enum-case allocators stay
         * case-sensitive (PHP identifiers are case-sensitive there).
         */
        public UniqueNames $names,
    ) {}

    /**
     * The tag group a component schema's classes belong to under the grouped
     * layout (issue #93), or null for the flat root (multi-group,
     * unreferenced, or reserved schemas). Attribution comes from the map
     * computed in generate(). A SYNTHESIZED inline-union variant (issue #38)
     * is not a component, so the map can never name it: it follows its base.
     * A named-component variant is attributed like every other component (the
     * closure walk links it to its base, so it lands with the base unless
     * another tag group also reaches it).
     */
    public function groupForSchema(string $schemaName): ?string
    {
        $groups = $this->schemaGroups;

        if (isset($groups[$schemaName])) {
            return $groups[$schemaName];
        }

        if (array_key_exists($schemaName, $this->discriminators->syntheticVariants())) {
            $base = $this->discriminators->baseOf($schemaName);
            if ($base !== null && isset($groups[$base])) {
                return $groups[$base];
            }
        }

        return null;
    }

    /**
     * The namespace a generated class is emitted into: the configured Data
     * namespace, extended by the class's tag group under the grouped layout
     * (issue #93).
     */
    public function namespaceFor(string $className): string
    {
        $group = $this->fileGroups[$className] ?? null;

        return $group === null ? $this->options->namespace : $this->options->namespace.'\\'.$group;
    }

    /**
     * The import FQCN for a runtime support class, resolved against the
     * consumer's own Support namespace (issue #40), and recorded as used so it is
     * inlined into the consumer's output. Generated code therefore imports its
     * rules and transformer from `<DataNamespace>\Support`, never from the
     * generator package, leaving the output self-contained.
     */
    public function supportImport(string $shortName): string
    {
        $this->usedSupportClasses[$shortName] = true;

        return $this->options->supportNamespace().'\\'.$shortName;
    }

    /**
     * Open a reference scope for a class being emitted (issue #93). Scopes
     * stack because emission is reentrant: an inline object property spawns a
     * nested class in the middle of its holder's emission. The class's group
     * must already be assigned in $fileGroups when the scope opens.
     */
    public function pushRefScope(string $className): void
    {
        $this->pushGroupScope($this->fileGroups[$className] ?? null);
    }

    /**
     * Open a scope that only establishes the tag group spawned nested classes
     * inherit (issue #93). Used by the alias and map classification passes,
     * which resolve types (and may spawn inline classes) before any class
     * emission runs; the refs recorded here are discarded on pop, because use
     * sites replay them from the cached {@see ResolvedType::$classRefs}.
     */
    public function pushGroupScope(?string $group): void
    {
        $this->refScopes[] = ['group' => $group, 'refs' => []];
    }

    /**
     * Close the current reference scope and return the generated classes it
     * recorded, sorted for deterministic import order.
     *
     * @return list<string>
     */
    public function popRefScope(): array
    {
        $scope = array_pop($this->refScopes);
        $refs = $scope === null ? [] : array_keys($scope['refs']);
        sort($refs);

        return $refs;
    }

    /**
     * Record generated classes the class currently being emitted references.
     * A no-op outside an emission scope (alias and map types resolved during
     * the classification passes are replayed at their use sites through
     * {@see ResolvedType::$classRefs} instead).
     */
    public function noteClassRef(string ...$classes): void
    {
        if ($this->refScopes === [] || $classes === []) {
            return;
        }

        $top = array_key_last($this->refScopes);
        foreach ($classes as $class) {
            $this->refScopes[$top]['refs'][$class] = true;
        }
    }

    /**
     * The tag group of the scope currently emitting, or null at the flat root
     * (and always null outside any scope). A nested inline class follows the
     * class (or alias/map component) that spawned it, so a holder's group
     * propagates to its whole inline subtree.
     */
    public function currentScopeGroup(): ?string
    {
        if ($this->refScopes === []) {
            return null;
        }

        return $this->refScopes[array_key_last($this->refScopes)]['group'];
    }

    /**
     * Merge the imports a class needs for its cross-group references (issue
     * #93): every recorded generated class that lives in a DIFFERENT group
     * gets a `use` of its real namespace. Same-group (and self) references
     * stay short-name-only, exactly like the flat layout, and a recorded name
     * that is not a generated class (the defensive `Data` fallback of a
     * variant whose base vanished) is never imported. In the flat layout every
     * group is null, so this returns the imports unchanged (re-sorting a list
     * that is already unique and sorted).
     *
     * @param  list<string>  $imports
     * @param  list<string>  $refs
     * @return list<string>
     */
    public function withCrossGroupImports(string $className, array $imports, array $refs): array
    {
        $group = $this->fileGroups[$className] ?? null;

        foreach ($refs as $ref) {
            if ($ref === $className || ! array_key_exists($ref, $this->fileGroups)) {
                continue;
            }

            if (($this->fileGroups[$ref] ?? null) === $group) {
                continue;
            }

            $imports[] = $this->namespaceFor($ref).'\\'.$ref;
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $imports;
    }

    /**
     * If a reference points at a pure-map component, return that component's
     * schema so the caller can derive the map value rules. Returns null when the
     * reference is not a pure-map component.
     */
    public function referencedMapSchema(ReferenceNode $reference): ?SchemaNode
    {
        $name = SchemaPointer::refName($reference->pointer());

        return $name !== null ? ($this->mapSchemas[$name] ?? null) : null;
    }

    /**
     * If a reference points at an emitted object Data-class component, return
     * that component's schema so the caller can read schema-level constraints
     * (minProperties/maxProperties, issue #72) that must be enforced at the use
     * site. Returns null for enums and unregistered names.
     */
    public function referencedObjectSchema(ReferenceNode $reference): ?SchemaNode
    {
        $name = SchemaPointer::refName($reference->pointer());
        if ($name === null) {
            return null;
        }

        $entry = $this->registry[$name] ?? null;

        return $entry !== null && $entry['kind'] === 'data' ? $entry['schema'] : null;
    }

    /**
     * If a reference points at a non-object alias component (scalar/array/union),
     * return that component's schema so the caller can derive its rules from the
     * underlying type. Returns null when the reference is not such an alias.
     */
    public function referencedAliasSchema(ReferenceNode $reference): ?SchemaNode
    {
        $name = SchemaPointer::refName($reference->pointer());

        return $name !== null ? ($this->aliasSchemas[$name] ?? null) : null;
    }

    /**
     * Follow a chained alias (`allOf: [{$ref}]` -> alias -> ... -> scalar/array/
     * union) to its terminal schema so rule derivation reads the constraint at
     * the end of the chain, not the thin allOf wrapper (which would yield only
     * presence rules). Cycle-guarded; a cyclic or dangling chain returns the last
     * schema reached. A non-chain alias is returned unchanged.
     *
     * @param  array<string, true>  $seen  alias names already followed
     */
    public function terminalAliasSchema(SchemaNode $schema, array $seen = []): SchemaNode
    {
        $ref = SchemaFacts::bareAllOfRef($schema);
        if ($ref === null) {
            return $schema;
        }

        $name = SchemaPointer::refName($ref->pointer());
        if ($name === null || isset($seen[$name])) {
            return $schema;
        }

        $next = $this->aliasSchemas[$name] ?? null;
        if ($next === null) {
            return $schema;
        }

        $seen[$name] = true;

        return $this->terminalAliasSchema($next, $seen);
    }

    /**
     * Resolve one `allOf` member to a concrete schema. Inline schemas pass
     * through. A `$ref` to a component schema is looked up in the registry,
     * guarded against cycles by tracking the component names already in flight.
     *
     * @param  array<string, true>  $seen
     * @return array{0: SchemaNode, 1: array<string, true>}|null resolved schema + updated cycle guard, or null if unresolvable
     */
    public function resolveMemberSchema(SchemaNode|ReferenceNode $member, array $seen): ?array
    {
        if ($member instanceof SchemaNode) {
            return [$member, $seen];
        }

        $name = SchemaPointer::refName($member->pointer());

        if ($name === null || isset($seen[$name]) || ! isset($this->registry[$name])) {
            return null;
        }

        $target = $this->registry[$name]['schema'];
        $seen[$name] = true;

        return [$target, $seen];
    }
}
