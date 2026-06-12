<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;

/**
 * Turns the schemas of an OpenAPI document into spatie/laravel-data classes
 * and native backed enums.
 *
 * Two passes keep $ref handling correct: the first classifies every component
 * schema and assigns it a final class name; the second emits source, resolving
 * cross-references through that registry. Inline object/array schemas spawn
 * nested classes during emission, bounded by a configurable depth guard.
 *
 * Output is deterministic: component schemas are processed in sorted order and
 * the returned files are keyed and ordered by class name.
 *
 * @internal
 */
final class ModelGenerator
{
    private const SCALARS = [
        'string' => 'string',
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
    ];

    /**
     * Short-names the emitter imports into every (or many) generated Data files.
     * A component schema must never take one as its class name: `Data` is the
     * Spatie base class every Data class `extends`, so a schema named `Data`
     * would emit `final class Data extends Data` (a fatal self-redeclaration);
     * the rest are framework types the constructors reference by short name.
     * Reserving them up front routes such a schema onto the suffix path
     * (`Data` -> `Data_2`), leaving the imports unshadowed.
     *
     * @var list<string>
     */
    private const RESERVED_CLASS_NAMES = [
        'Data',
        'DataCollection',
        'DataCollectionOf',
        'Optional',
        'MapName',
        'WithTransformer',
        'MapObjectTransformer',
        'Rule',
        // Multipart file parts (issue #75) type their properties UploadedFile
        // by short name, so a component class with that exact name (an enum
        // would carry no Data suffix) must not shadow the import.
        'UploadedFile',
    ];

    /**
     * The PHP type a multipart file part (issue #75) is hydrated into.
     */
    private const UPLOADED_FILE_FQCN = 'Illuminate\\Http\\UploadedFile';

    // Deepest array nesting a query parameter may have: bracket query strings beyond a few levels are not a real wire format.
    private const QUERY_MAX_ARRAY_DEPTH = 4;

    private UniqueNames $names;

    /**
     * Discriminated-union registry for the current generate() run: which
     * component schemas are union bases (oneOf/anyOf + discriminator over object
     * members) and which are their variants. Drives the abstract base + variant
     * emission and the `$ref`-to-base typing. Rebuilt per generate() call.
     */
    private DiscriminatorRegistry $discriminators;

    /**
     * Component schema name => [className, kind, schema].
     *
     * @var array<string, array{class: string, kind: 'data'|'enum', schema: Schema}>
     */
    private array $registry = [];

    /**
     * Component schema name => writable variant class name, for schemas that
     * split into read/write variants. Used by the public registry() accessor.
     *
     * @var array<string, string>
     */
    private array $writeClasses = [];

    /**
     * Component schema name => resolved array type, for pure-map components
     * (`type: object` with only `additionalProperties`, no named properties).
     * Such a component is not emitted as its own Data class: a `$ref` to it
     * inlines the typed array (`array<string, int>`) at the use site instead of
     * pointing at an empty class. See resolveReference().
     *
     * @var array<string, ResolvedType>
     */
    private array $mapAliases = [];

    /**
     * Component schema name => the original Schema, for pure-map components.
     * Kept separately from $registry (which holds emitted Data/enum classes)
     * so a $ref to a pure-map component can recover its value schema for rules.
     *
     * @var array<string, Schema>
     */
    private array $mapSchemas = [];

    /**
     * Component schema name => resolved underlying type, for non-object alias
     * components: a top-level component that is itself a scalar (`{type: string,
     * format: date-time}`), an array, or a oneOf/anyOf union, with no object
     * `properties`. Such a component is a TYPE ALIAS, not a Data class: a `$ref`
     * to it resolves to the underlying type at the use site instead of pointing
     * at an empty class (which would silently fail to hydrate). Mirrors
     * $mapAliases. See resolveReference() and isNonObjectAlias().
     *
     * @var array<string, ResolvedType>
     */
    private array $aliasTypes = [];

    /**
     * Component schema name => the original Schema, for non-object alias
     * components. Kept separately so a `$ref` to such an alias can recover the
     * underlying schema and reuse buildRules() at the use site (so a date-time
     * alias still contributes its date-time rule, a length-bounded string alias
     * its max:/min:, etc.). Mirrors $mapSchemas.
     *
     * @var array<string, Schema>
     */
    private array $aliasSchemas = [];

    /**
     * @var array<string, GeneratedFile>
     */
    private array $files = [];

    /**
     * Per-operation query Data classes (issue #63), emitted on demand by
     * {@see generateQueryData()} AFTER generate() ran, keyed by class name.
     * Kept apart from $files because generate() already returned its file set
     * when the server scaffold asks for query classes; the planner collects
     * these separately via {@see queryFiles()}.
     *
     * @var array<string, GeneratedFile>
     */
    private array $queryFiles = [];

    /**
     * Per-operation request-body Data classes (issue #76), emitted on demand by
     * {@see generateBodyData()} AFTER generate() ran, keyed by class name. The
     * bucket also holds every nested class an inline body spawned (a nested
     * object property becomes its own Data class, exactly like a component's).
     * Kept apart from $files for the same reason as $queryFiles: generate()
     * already returned its file set when the server scaffold asks for body
     * classes; the planner collects these separately via {@see bodyFiles()}.
     *
     * @var array<string, GeneratedFile>
     */
    private array $bodyFiles = [];

    /**
     * Non-fatal diagnostics gathered during a generate() run, keyed by the
     * warning text so the same finding (re-seen across the read/write variants of
     * one schema, or a recursive inline emit) is recorded only once. The CLI
     * surfaces these verbatim. They never change what is generated: the channel
     * only reports silent information loss the spec forced on us.
     *
     * @var array<string, true>
     */
    private array $warnings = [];

    /**
     * Where the resolver currently is, leading every degradation warning
     * (issue #67): 'Schema "Holder"' while a component (or its read/write
     * variants and inline nested classes) is emitted, 'Query parameters of
     * operation GET /pets' during query-class generation. Deliberately
     * schema-grained, not property-grained: combined with the text-keyed
     * dedupe above, the read and write variants of one schema report a shared
     * degradation once instead of spamming the channel per generated class.
     */
    private string $warningContext = 'Document';

    /**
     * Whether the class currently being emitted is the ROOT of a
     * multipart/form-data request body (issue #75). Only the root properties
     * of such a body are form parts, so only there a `type: string,
     * format: binary` property (or an array of them) becomes an UploadedFile
     * with `file` rules; a binary string nested deeper sits inside a
     * JSON-serialized part and keeps its plain string typing. Set by
     * generateMultipartBodyData() around its emitData() call and consulted
     * together with `depth === 0`, mirroring the $warningContext pattern.
     */
    private bool $multipartBody = false;

    /**
     * Runtime support classes the current generate() run actually referenced
     * (issue #40), keyed by short name so each is recorded once. Drives the
     * inlined Support file set: only the support classes a spec uses are emitted
     * into the consumer's Support namespace, so the set stays minimal and
     * deterministic. Rebuilt per generate() call.
     *
     * @var array<string, true>
     */
    private array $usedSupportClasses = [];

    /**
     * Emitted class name => its tag group under the grouped data layout
     * (issue #93), or null for the flat root. Holds EVERY generated class
     * (components, write variants, nested inline classes, synthesized union
     * variants, per-operation query and body classes); in the flat default
     * every value is null. Drives the per-file namespace, the subdirectory,
     * and the cross-group import decisions. Rebuilt per generate() call.
     *
     * @var array<string, ?string>
     */
    private array $fileGroups = [];

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

    /**
     * The tag-grouped layout attribution for the current generate() run
     * (issue #93): schema name => owning group. Computed from the document
     * itself unless the options injected a precomputed map (a test seam).
     *
     * @var array<string, string>
     */
    private array $schemaGroups = [];

    public function __construct(
        private readonly GeneratorOptions $options = new GeneratorOptions,
    ) {
        $this->names = new UniqueNames(self::RESERVED_CLASS_NAMES);
    }

    /**
     * @return array<string, GeneratedFile> class name => file, ordered by class name
     */
    public function generate(OpenApi $document): array
    {
        $this->names = new UniqueNames(self::RESERVED_CLASS_NAMES);
        $this->registry = [];
        $this->writeClasses = [];
        $this->mapAliases = [];
        $this->mapSchemas = [];
        $this->aliasTypes = [];
        $this->aliasSchemas = [];
        $this->files = [];
        $this->queryFiles = [];
        $this->bodyFiles = [];
        $this->warnings = [];
        $this->warningContext = 'Document';
        $this->multipartBody = false;
        $this->usedSupportClasses = [];
        $this->fileGroups = [];
        $this->refScopes = [];

        // Tag-grouped data layout (issue #93, the only layout): attribute each
        // component schema to the tag group that solely owns it, via the same
        // transitive walk as the subset closure. Computed here from the
        // document itself so EVERY caller gets the grouped layout; the options
        // map is an injection seam for unit tests of the emission side.
        $this->schemaGroups = $this->options->schemaGroups
            ?? (new SchemaClosure)->attributeByTag($document);

        $schemas = $this->componentSchemas($document);
        ksort($schemas);

        // Find discriminated object unions before classifying schemas: a union
        // base (a oneOf/anyOf of object $refs plus a discriminator) becomes an
        // abstract Data class rather than a non-object alias, and its variants
        // extend it. Built from the same component map so it is deterministic.
        $this->discriminators = new DiscriminatorRegistry($schemas);

        // Surface why any discriminated union degraded to presence-only (a
        // non-object member, a multi-base conflict, or a base left with no
        // claimable variants) through the same diagnostics channel as the other
        // silent-information-loss warnings.
        foreach ($this->discriminators->warnings() as $warning) {
            $this->warnings[$warning] = true;
        }

        // The inline-union form (#38) has no named component per variant: the
        // registry synthesized a deterministic, collision-safe name and schema for
        // each inline member. Merge those into the schema map so the rest of the
        // pipeline (registration, emission, $ref resolution) treats them exactly
        // like a named variant component. Sorted keys keep output deterministic.
        foreach ($this->discriminators->syntheticVariants() as $variantName => $variantSchema) {
            $schemas[$variantName] = $variantSchema;
        }
        ksort($schemas);

        foreach ($schemas as $name => $schema) {
            // A discriminated-union base is a Data class (an abstract
            // PropertyMorphableData), even though it is structurally a
            // oneOf/anyOf alias. Register it as such before the alias check
            // below would otherwise skip it.
            if ($this->discriminators->isBase($name)) {
                $class = $this->names->reserve($this->withSuffix(PhpIdentifier::toClassName($name)));
                $this->registry[$name] = ['class' => $class, 'kind' => 'data', 'schema' => $schema];
                $this->fileGroups[$class] = $this->groupForSchema($name);

                continue;
            }

            // A pure-map component (only additionalProperties, no named
            // properties) is not a Data class: it is a typed array. Record it as
            // a map alias and skip emitting an empty class. References to it
            // inline the array type at the use site.
            if ($this->isPureMap($schema)) {
                continue;
            }

            // A non-object alias component (a scalar/array/union with no object
            // properties) is a TYPE ALIAS, not a Data class. Skip emitting an
            // empty class; the second pass resolves it to its underlying type
            // and references inline that type at the use site.
            if ($this->isNonObjectAlias($schema)) {
                continue;
            }

            $kind = $this->isEnum($schema) ? 'enum' : 'data';
            $base = PhpIdentifier::toClassName($name);
            $class = $kind === 'data' ? $this->withSuffix($base) : $base;
            $class = $this->names->reserve($class);

            $this->registry[$name] = ['class' => $class, 'kind' => $kind, 'schema' => $schema];
            $this->fileGroups[$class] = $this->groupForSchema($name);
        }

        // Second classification pass: resolve each pure-map component to its
        // array type. Done after the registry is built so a map whose value is a
        // `$ref` resolves the referenced class. Sorted for determinism.
        foreach ($schemas as $name => $schema) {
            if ($this->isPureMap($schema)) {
                $this->warningContext = sprintf('Schema "%s"', $name);
                $this->mapSchemas[$name] = $schema;
                // A nested class spawned from the map's value schema follows
                // the map component's own tag group (issue #93); the recorded
                // refs are discarded (use sites replay the cached classRefs).
                $this->pushGroupScope($this->groupForSchema($name));
                $this->mapAliases[$name] = $this->mapType($schema, PhpIdentifier::toClassName($name), 0, 'all');
                $this->popRefScope();
            }
        }

        // Record the schemas of non-object alias components first (so rules at a
        // use site can recover the underlying schema), then resolve each to its
        // underlying type. Resolution is lazy and transitive: an alias whose
        // type is itself a `$ref` to another alias (alias -> alias) chains
        // through resolveReference, guarded against cycles. Sorted for
        // determinism.
        foreach ($schemas as $name => $schema) {
            // A discriminated-union base is an (abstract) Data class, not a
            // non-object alias: never record it as an alias, or a $ref to it
            // would resolve to mixed instead of the base type.
            if ($this->discriminators->isBase($name)) {
                continue;
            }
            if ($this->isNonObjectAlias($schema)) {
                $this->aliasSchemas[$name] = $schema;
            }
        }

        // Promote chained aliases: a registry component that is a thin
        // `allOf: [{$ref}]` whose chain terminates at a non-object alias is
        // itself an alias, not a Data class. Done now (not in the first pass)
        // because it needs the direct-alias set above to know the target's kind.
        // A chain that terminates at an object Data class stays a Data class
        // (its read/write split and server-scaffold registry entry are kept).
        $this->promoteChainedAliases($schemas);

        foreach ($this->aliasSchemas as $name => $schema) {
            $this->resolveAlias($name);
        }

        foreach ($this->registry as $name => $entry) {
            // One warning context per component: the read/write variants and
            // every inline nested class of this schema report a shared
            // degradation once (issue #67).
            $this->warningContext = sprintf('Schema "%s"', $name);

            if ($entry['kind'] === 'enum') {
                $this->emitEnum($entry['class'], $entry['schema']);
            } elseif ($this->discriminators->isBase($name)) {
                // The abstract base of a discriminated union: only the
                // discriminator property, marked for morph, plus a match() that
                // maps each discriminator value to its variant Data class.
                $this->emitDiscriminatorBase($name, $entry['class']);
            } elseif ($this->discriminators->isVariant($name)) {
                // A variant extends its base, forwards the discriminator, and
                // declares only its own (non-discriminator) properties.
                $this->emitVariant($name, $entry['class'], $entry['schema']);
            } elseif ($this->hasReadWriteFlags($entry['schema'])) {
                // The spec marks fields readOnly/writeOnly: split into a read
                // variant (drops writeOnly) and a write variant (drops readOnly).
                $this->emitData($entry['class'], $entry['schema'], 0, 'read');
                $writeClass = $this->names->reserve($this->withSuffix($this->stripSuffix($entry['class']).'Writable'));
                $this->writeClasses[$name] = $writeClass;
                // The write variant is the same component, so it follows the
                // component's tag group (issue #93).
                $this->fileGroups[$writeClass] = $this->fileGroups[$entry['class']] ?? null;
                $this->emitData($writeClass, $entry['schema'], 0, 'write');
            } else {
                $this->emitData($entry['class'], $entry['schema'], 0);
            }
        }

        ksort($this->files);

        return $this->files;
    }

    /**
     * The component-schema registry after a generate() run, for downstream
     * emitters (server scaffold) that need to map a $ref to the class it became.
     * Each entry carries the read/data class, the writable variant (when the
     * spec split read/write), and whether the schema became a Data class or enum.
     *
     * @return array<string, array{dataClass: string, writeClass: ?string, kind: 'data'|'enum'}>
     */
    public function registry(): array
    {
        $result = [];

        foreach ($this->registry as $name => $entry) {
            $result[$name] = [
                'dataClass' => $entry['class'],
                'writeClass' => $this->writeClasses[$name] ?? null,
                'kind' => $entry['kind'],
            ];
        }

        return $result;
    }

    /**
     * Non-fatal diagnostics from the last generate() run, sorted for determinism.
     * Each entry flags information the spec carried that OpenAPI ignores, so the
     * generated code is correct but quietly loses something a reader expected
     * (currently: a non-standard per-property `required` key). Behavior is never
     * altered by these; they exist so the CLI can warn instead of staying silent.
     *
     * Added as a getter rather than changing the generate() return type so every
     * existing caller (the GenerationPlanner and its check path) keeps compiling
     * unchanged: callers that care opt in by calling warnings() after generate().
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = array_keys($this->warnings);
        sort($warnings);

        return $warnings;
    }

    /**
     * Emit a per-operation query Data class (issue #63) for an operation's
     * `in: query` parameters, reusing the EXACT rules/type pipeline the body
     * Data classes go through (resolveType, buildRules, renderProperty), so a
     * query constraint is enforced with the same fidelity as a body constraint.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` parameters
     * against the run's component registry and alias caches. The collected
     * files are exposed via {@see queryFiles()}; the class name is reserved in
     * the run's allocator so a query class can never collide with a component
     * class (a clash suffixes deterministically, e.g. `..._2`).
     *
     * Each query class also carries a `fromQuery(Request $request)` factory
     * that validates and hydrates from `$request->query()` ONLY. For an
     * operation with a request body this is the supported access path (the
     * class is not injected, or body fields would bleed into query
     * validation); for a body-less operation laravel-data picks `fromQuery`
     * up as the magic creation method on container injection, so the typed
     * controller parameter hydrates through the same query-only path.
     *
     * A parameter whose serialization cannot round-trip through Laravel's
     * flat `key=value` / `key[]=value` query parsing (style deepObject,
     * space/pipe-delimited, a non-exploded array, an object or object-map
     * shape, a content-typed parameter) is skipped with a warning rather than
     * given rules that would false-reject valid requests.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "GET /pets", for warning messages
     * @param  list<Parameter>  $parameters  the operation's `in: query` parameters, in spec order
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved query class name, or null when every parameter was skipped
     */
    public function generateQueryData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        // Degradation warnings inside the query pipeline name the operation,
        // not a schema: the parameters being resolved are operation-owned.
        $this->warningContext = sprintf('Query parameters of operation %s', $operationLabel);

        $supported = [];
        foreach ($parameters as $parameter) {
            $reason = $this->querySkipReason($parameter);

            if ($reason !== null) {
                $name = is_string($parameter->name) ? $parameter->name : '(unnamed)';
                $this->warnings[sprintf(
                    'Operation %s: query parameter "%s" was skipped: %s.',
                    $operationLabel,
                    $name,
                    $reason,
                )] = true;

                continue;
            }

            $supported[] = $parameter;
        }

        if ($supported === []) {
            return null;
        }

        $className = $this->names->reserve($this->withSuffix($baseName.'Query'));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93); in the flat layout the group is null.
        $this->fileGroups[$className] = $this->groupForTag($tag);
        $this->pushRefScope($className);

        $base = $this->stripSuffix($className);
        $propertyNames = new UniqueNames;

        $paramsRequired = [];
        $paramsOptional = [];
        $rules = [];
        $usesRule = false;
        $booleanNames = [];

        foreach ($supported as $parameter) {
            $wireName = (string) $parameter->name;
            $schema = $parameter->schema;
            if (! $schema instanceof Schema && ! $schema instanceof Reference) {
                // querySkipReason() guarantees a schema; defensive for PHPStan.
                continue;
            }

            // Distinct wire names can collapse to the same identifier
            // (first_name + firstName); suffix collisions like emitData does.
            $propertyName = $propertyNames->reserve(PhpIdentifier::toPropertyName($wireName));
            $type = $this->resolveType($schema, $base.PhpIdentifier::toClassName($wireName), 1);
            $this->noteClassRef(...$type->classRefs);

            // The form style serializes a boolean as ?flag=true / ?flag=false,
            // but Laravel's `boolean` rule only understands 1/0 (and PHP's
            // coercive bool cast would turn the string "false" into TRUE), so
            // fromQuery() maps the literals to '1'/'0' before validating.
            if ($type->declaration === 'bool') {
                $booleanNames[] = $wireName;
            }

            // A scalar `default` makes the parameter optional on input even when
            // the spec marks it required, exactly like a defaulted body property:
            // an omitted value is filled by the default.
            $default = $this->defaultValue($schema, $type);
            $isRequired = $parameter->required === true && $default === null;

            // A parameter can be deprecated on the Parameter object itself or on
            // its schema; either way the property carries the `@deprecated` tag.
            $deprecationTag = $parameter->deprecated === true ? '@deprecated' : $this->deprecationTag($schema);

            $rendered = $this->renderProperty($wireName, $propertyName, $type, $isRequired, $default, $deprecationTag);

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            [$propertyRules, $wildcardRules, $uses] = $this->buildRules($schema, $isRequired, $type);
            $rules[$wireName] = $propertyRules;
            foreach ($wildcardRules as $suffix => $ruleList) {
                if ($ruleList !== []) {
                    $rules[$wireName.$suffix] = $ruleList;
                }
            }
            $usesRule = $usesRule || $uses;
        }

        $params = array_merge($paramsRequired, $paramsOptional);

        if ($params === []) {
            // Every surviving parameter lacked a usable schema (defensive; the
            // skip check above already filtered these). No useful class to emit.
            $this->popRefScope();

            return null;
        }

        // The fromQuery factory references Request by short name.
        $imports = $this->collectImports($params, $usesRule, $rules);
        $imports[] = 'Illuminate\\Http\\Request';
        $imports = array_values(array_unique($imports));
        sort($imports);

        // A referenced enum or Data class may live in another tag group
        // (issue #93); import those from their real namespaces.
        $imports = $this->withCrossGroupImports($className, $imports, $this->popRefScope());

        $classDoc = [
            'Query parameters of '.$this->docblockSafe($operationLabel).'.',
        ];

        $this->queryFiles[$className] = new GeneratedFile(
            $className,
            $this->renderDataClass($className, $params, $imports, $rules, $classDoc, fromQueryBooleans: $booleanNames),
            $this->fileGroups[$className] ?? null,
        );

        return $className;
    }

    /**
     * The per-operation query Data classes emitted since the last generate()
     * run (issue #63), keyed and ordered by class name. Exposed as a getter,
     * mirroring supportFiles(): generate() already returned its file set when
     * the server scaffold asks for these.
     *
     * @return array<string, GeneratedFile>
     */
    public function queryFiles(): array
    {
        $files = $this->queryFiles;
        ksort($files);

        return $files;
    }

    /**
     * Emit a per-operation request-body Data class (issue #76) for an inline
     * JSON request-body schema, reusing the EXACT emission pipeline the
     * component Data classes go through (emitData: properties, rules(), nested
     * inline classes, closed-object enforcement, defaults), so an inline body
     * is validated and hydrated with the same fidelity as a `$ref` body.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` properties
     * against the run's component registry and alias caches. The collected
     * files (the body class plus any nested classes it spawned) are exposed via
     * {@see bodyFiles()}; the class name is reserved in the run's allocator so
     * a body class can never collide with a component class or a query class
     * (a clash suffixes deterministically, e.g. `..._2`).
     *
     * Only an OBJECT schema synthesizes a class, mirroring the `$ref` path: a
     * `$ref` body is typed only when it points at a generated Data class, and a
     * non-object component (a scalar/array/union alias, a pure map, an enum)
     * falls back to `Illuminate\Http\Request` there too. A non-object inline
     * schema therefore returns null with a warning, and the caller keeps the
     * established Request fallback.
     *
     * A schema that splits fields with readOnly/writeOnly emits the WRITE shape
     * (readOnly properties dropped), the same variant a component `$ref` body
     * is typed against.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "POST /pets", for warning messages
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class (and its nested classes) in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved body class name, or null when the schema cannot type a body
     */
    public function generateBodyData(string $baseName, string $operationLabel, Schema $schema, ?string $tag = null): ?string
    {
        // Degradation warnings inside the body pipeline name the operation,
        // not a schema: an inline body schema is operation-owned.
        $this->warningContext = sprintf('Request body of operation %s', $operationLabel);

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->warnings[sprintf(
                'Operation %s: the inline request body schema was not generated as a typed Data class (%s); the controller method falls back to Illuminate\Http\Request.',
                $operationLabel,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->names->reserve($this->withSuffix($baseName.'Request'));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93); nested classes it spawns follow it through the
        // emission scope. Null in the flat layout.
        $this->fileGroups[$className] = $this->groupForTag($tag);

        // emitData() writes the class (and every nested class it spawns) into
        // $files, which generate() already returned to its caller. Emit into a
        // clean bucket and collect the run's output into $bodyFiles, so the
        // planner picks the body classes up via bodyFiles() exactly like the
        // query classes.
        $mainFiles = $this->files;
        $this->files = [];

        $variant = $this->hasReadWriteFlags($schema) ? 'write' : 'all';
        $this->emitData($className, $schema, 0, $variant, ['Request body of '.$this->docblockSafe($operationLabel).'.']);

        foreach ($this->files as $name => $file) {
            $this->bodyFiles[$name] = $file;
        }
        $this->files = $mainFiles;

        return $className;
    }

    /**
     * The per-operation request-body Data classes emitted since the last
     * generate() run (issue #76), keyed and ordered by class name. Exposed as
     * a getter, mirroring queryFiles(): generate() already returned its file
     * set when the server scaffold asks for these.
     *
     * @return array<string, GeneratedFile>
     */
    public function bodyFiles(): array
    {
        $files = $this->bodyFiles;
        ksort($files);

        return $files;
    }

    /**
     * Emit a per-operation request-body Data class for a multipart/form-data
     * body (issue #75), through the exact emitData pipeline the JSON bodies of
     * issue #76 use, with ONE multipart-specific twist: a root property that is
     * a `type: string, format: binary` schema (or an array of binary items) is
     * an uploaded file part, typed `UploadedFile` (or `array<int, UploadedFile>`)
     * with Laravel's `file` rule plus a `mimetypes:` constraint when the part
     * pins its media type via `contentMediaType`. Every non-binary part is
     * validated exactly like a JSON body field.
     *
     * Unlike the JSON path, a schema-level `$ref` body is NOT typed against the
     * component's class: that class was emitted with JSON semantics, where a
     * binary string property is a plain string whose `string` rule would
     * false-reject every actual upload. Instead the referenced component schema
     * is re-emitted as a per-operation class with multipart semantics. A `$ref`
     * that does not resolve to an object component, and any non-object shape,
     * keeps the documented Request fallback with a warning, mirroring
     * {@see generateBodyData()}.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "POST /pets", for warning messages
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class (and its nested classes) in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved body class name, or null when the schema cannot type a body
     */
    public function generateMultipartBodyData(string $baseName, string $operationLabel, Schema|Reference $schema, ?string $tag = null): ?string
    {
        $this->warningContext = sprintf('Multipart request body of operation %s', $operationLabel);

        if ($schema instanceof Reference) {
            $name = $this->refName($schema->getReference());
            if ($name === null || ! isset($this->registry[$name]) || $this->registry[$name]['kind'] !== 'data') {
                $this->warnings[sprintf(
                    'Operation %s: the multipart/form-data request body $ref "%s" does not resolve to an object component schema; the controller method falls back to Illuminate\Http\Request.',
                    $operationLabel,
                    $schema->getReference(),
                )] = true;

                return null;
            }

            $schema = $this->registry[$name]['schema'];
        }

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->warnings[sprintf(
                'Operation %s: the multipart/form-data request body schema was not generated as a typed Data class (%s); the controller method falls back to Illuminate\Http\Request.',
                $operationLabel,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->names->reserve($this->withSuffix($baseName.'Request'));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93), exactly like generateBodyData(); nested classes
        // it spawns follow it through the emission scope. Null when flat.
        $this->fileGroups[$className] = $this->groupForTag($tag);

        // Same bucket discipline as generateBodyData(): emit into a clean
        // bucket and collect into $bodyFiles for the planner.
        $mainFiles = $this->files;
        $this->files = [];
        $this->multipartBody = true;

        $variant = $this->hasReadWriteFlags($schema) ? 'write' : 'all';
        $this->emitData($className, $schema, 0, $variant, ['Multipart request body of '.$this->docblockSafe($operationLabel).'.']);

        $this->multipartBody = false;
        foreach ($this->files as $name => $file) {
            $this->bodyFiles[$name] = $file;
        }
        $this->files = $mainFiles;

        return $className;
    }

    /**
     * Why an inline request-body schema cannot become a typed Data class, or
     * null when it can. The bar mirrors the component classification exactly:
     * only the shapes that would have become a `kind: data` component (an
     * object with named properties, an allOf merge, or a legitimately empty
     * `type: object`) synthesize a class. A pure map resolves to a typed array
     * (no class to type a param against), and a scalar, array, union, or enum
     * shape is a non-object alias there too. An enum (including the float-enum
     * form) is skipped deliberately: the component pipeline wraps a float enum
     * in a single-`value` Data class, but for a request BODY that wrapper would
     * change the wire format (the payload would have to nest under `value`),
     * false-rejecting every spec-valid request.
     */
    private function bodySkipReason(Schema $schema): ?string
    {
        if ($this->isPureMap($schema)) {
            return 'it is an object map with only additionalProperties, which resolves to a typed array, not a Data class';
        }

        if ($this->isEnum($schema) || $this->isScalarEnumComponent($schema)) {
            return 'it is an enum, not an object shape';
        }

        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return 'it is a oneOf/anyOf union, not a single object shape';
        }

        // A bare allOf-of-one-$ref is an alias of its target (the chained-alias
        // shape, see promoteChainedAliases). When the target is a generated
        // Data class the allOf merge below recovers its full property set, so
        // synthesis stays correct. A target that is NOT an object Data class
        // (a scalar/array/union alias, a map, an enum, or an unresolved
        // pointer) would merge to an empty class that silently drops the
        // payload, so it keeps the Request fallback instead.
        $aliasRef = $this->bareAllOfRef($schema);
        if ($aliasRef !== null) {
            $name = $this->refName($aliasRef->getReference());
            if ($name === null || ! isset($this->registry[$name]) || $this->registry[$name]['kind'] !== 'data') {
                return 'it is an allOf alias of a non-object component, so no Data class can type it';
            }
        }

        $primary = $this->normalizeTypes($schema)[0] ?? null;

        if ($primary === 'object'
            || $this->notEmptyArray($schema->properties)
            || $this->notEmptyArray($schema->allOf)
        ) {
            return null;
        }

        return 'it is not an object schema';
    }

    /**
     * Classify one root property of a multipart/form-data body (issue #75) as
     * an uploaded-file part, or null for a regular field. `kind` is 'file' for
     * a binary string and 'files' for an array of binary items; `schema` is
     * the (alias-resolved) part schema the nullability and array-count bounds
     * are read from; `leaf` is the binary string schema the `mimetypes:`
     * constraint is read from (the items schema for an array part).
     *
     * A `$ref` part (or array items entry) is followed through the non-object
     * alias caches so `file: {$ref: BinaryFile}` is still recognized as an
     * upload. Without that resolution the alias path in buildRules() would
     * emit a `string` rule that false-rejects every actual UploadedFile, which
     * is worse than the old no-validation fallback. A `$ref` to a Data class
     * or enum returns null and keeps its normal typing.
     *
     * @return array{kind: 'file'|'files', schema: Schema, leaf: Schema}|null
     */
    private function multipartFilePart(Schema|Reference $schema): ?array
    {
        $resolved = $this->multipartPartSchema($schema);
        if ($resolved === null) {
            return null;
        }

        if ($this->isBinaryString($resolved)) {
            return ['kind' => 'file', 'schema' => $resolved, 'leaf' => $resolved];
        }

        if (($this->normalizeTypes($resolved)[0] ?? null) === 'array') {
            $items = $resolved->items;
            if ($items instanceof Schema || $items instanceof Reference) {
                $leaf = $this->multipartPartSchema($items);
                if ($leaf !== null && $this->isBinaryString($leaf)) {
                    return ['kind' => 'files', 'schema' => $resolved, 'leaf' => $leaf];
                }
            }
        }

        return null;
    }

    /**
     * Resolve a multipart part schema for binary-file detection: an inline
     * Schema as-is, a `$ref` (or chained allOf-of-$ref alias) to a non-object
     * alias component to its terminal schema. Null for anything else.
     */
    private function multipartPartSchema(Schema|Reference $schema): ?Schema
    {
        if ($schema instanceof Schema) {
            return $schema;
        }

        $alias = $this->referencedAliasSchema($schema);

        return $alias === null ? null : $this->terminalAliasSchema($alias);
    }

    private function isBinaryString(Schema $schema): bool
    {
        return ($this->normalizeTypes($schema)[0] ?? null) === 'string' && $schema->format === 'binary';
    }

    /**
     * The resolved PHP type of a multipart file part: `UploadedFile` for a
     * single file, a typed `array<int, UploadedFile>` for an array of files.
     *
     * @param  array{kind: 'file'|'files', schema: Schema, leaf: Schema}  $part
     */
    private function multipartFileType(array $part): ResolvedType
    {
        $nullable = $this->isNullable($part['schema']);

        if ($part['kind'] === 'file') {
            return new ResolvedType('UploadedFile', $nullable, null, [self::UPLOADED_FILE_FQCN]);
        }

        return new ResolvedType('array', $nullable, 'array<int, UploadedFile>', [self::UPLOADED_FILE_FQCN]);
    }

    /**
     * The validation rules of a multipart file part: presence as usual, then
     * `file` (plus the `mimetypes:` constraint) on the value itself for a
     * single file, or `array` plus the spec's minItems/maxItems bounds with
     * the file rules on the `.*` wildcard for an array of files.
     *
     * @param  array{kind: 'file'|'files', schema: Schema, leaf: Schema}  $part
     * @return array{0: list<string>, 1: array<string, list<string>>, 2: bool} property rules,
     *                                                                         wildcard item rules keyed by suffix, whether Rule:: is used
     */
    private function multipartFileRules(array $part, bool $required, ResolvedType $type): array
    {
        $rules = $this->presenceRules($required, $type->nullable);

        if ($part['kind'] === 'file') {
            return [array_merge($rules, $this->fileLeafRules($part['leaf'])), [], false];
        }

        return [
            array_merge($rules, ["'array'"], $this->arrayCountRules($part['schema'])),
            ['.*' => $this->fileLeafRules($part['leaf'])],
            false,
        ];
    }

    /**
     * The per-file rules of one uploaded-file value: Laravel's `file` rule,
     * plus a `mimetypes:` constraint when the schema pins the part's media
     * type via `contentMediaType` (a JSON Schema 2020-12 keyword OpenAPI 3.1
     * admits). Deliberately NO size rule: OpenAPI has no standard keyword for
     * a file's byte size (`maxLength` bounds a STRING's character length, and
     * Laravel's file `max:` counts kilobytes), so no clean mapping exists and
     * none is invented.
     *
     * @return list<string>
     */
    private function fileLeafRules(Schema $schema): array
    {
        $rules = ["'file'"];

        $mediaType = $this->contentMediaTypeOf($schema);
        if ($mediaType !== null) {
            $rules[] = "'mimetypes:".$mediaType."'";
        }

        return $rules;
    }

    /**
     * The schema's `contentMediaType`, when it is a well-formed media type.
     * cebe has no typed attribute for the keyword, so it is read from the
     * serialized data. The spec is untrusted input and the value lands inside
     * a single-quoted rule string, so anything not matching the strict
     * type/subtype token shape (including the `image/*` wildcard form
     * Laravel's mimetypes rule understands) is dropped rather than escaped.
     */
    private function contentMediaTypeOf(Schema $schema): ?string
    {
        $serialized = (array) $schema->getSerializableData();
        $value = $serialized['contentMediaType'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return preg_match('~^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*/(\*|[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*)$~', $value) === 1
            ? $value
            : null;
    }

    /**
     * Why a query parameter cannot become a typed, validated property, or null
     * when it can. The bar: the value must arrive through Laravel's flat query
     * parsing (`key=value` scalars, `key[]=value` arrays) in a shape the
     * generated rules describe. Anything else is skipped with a warning;
     * generating wrong rules (false-rejecting valid requests) would be worse
     * than generating none.
     */
    private function querySkipReason(Parameter $parameter): ?string
    {
        if (! is_string($parameter->name) || $parameter->name === '') {
            return 'it has no usable name';
        }

        $style = $parameter->style;
        if ($style === 'deepObject') {
            return 'style "deepObject" is not supported yet';
        }
        if ($style === 'spaceDelimited' || $style === 'pipeDelimited') {
            return 'style "'.$style.'" serializes the array into a single delimited value, which the generated array rules cannot validate';
        }

        $schema = $parameter->schema;
        if (! $schema instanceof Schema && ! $schema instanceof Reference) {
            return 'it declares no schema (content-typed query parameters are not supported yet)';
        }

        // A non-exploded form array arrives as ONE comma-joined string
        // (?ids=1,2,3), not the per-item ?ids[]=1&ids[]=2 shape the generated
        // `array` rule validates.
        if ($parameter->explode === false && $this->isQueryArraySchema($schema)) {
            return 'a non-exploded (explode: false) array arrives as a single comma-joined value, which the generated array rules cannot validate';
        }

        return $this->queryShapeSkipReason($schema, 0);
    }

    /**
     * Whether a query parameter's schema is an array (directly, or through a
     * non-object alias component that resolves to an array). Used only for the
     * explode: false check above.
     */
    private function isQueryArraySchema(Schema|Reference $schema): bool
    {
        if ($schema instanceof Reference) {
            $name = $this->refName($schema->getReference());

            return $name !== null
                && isset($this->aliasSchemas[$name])
                && $this->resolveAlias($name)->declaration === 'array';
        }

        return ($this->normalizeTypes($schema)[0] ?? null) === 'array';
    }

    /**
     * The shape-level skip reason for a query parameter schema, or null when
     * the shape survives flat form serialization: scalars, enums (inline or
     * `$ref` to a generated enum), scalar unions, and arrays of those. Objects,
     * object maps, and arrays of objects have no flat `key=value` form, so they
     * are skipped. Recurses through array items so an array-of-array-of-object
     * is caught at any depth (bounded; deeper nesting is rejected outright,
     * since bracket query strings beyond a few levels are not a real wire
     * format).
     */
    private function queryShapeSkipReason(Schema|Reference $schema, int $depth): ?string
    {
        if ($depth > self::QUERY_MAX_ARRAY_DEPTH) {
            return 'it nests arrays too deeply for query-string serialization';
        }

        if ($schema instanceof Reference) {
            $name = $this->refName($schema->getReference());
            if ($name === null) {
                // External or non-schema pointer: degrades to mixed,
                // presence-only, exactly like a body property would.
                return null;
            }

            if (isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
                return 'it is an object, which has no flat query-string serialization';
            }

            if (isset($this->mapSchemas[$name])) {
                return 'it is an object map, which has no flat query-string serialization';
            }

            if (isset($this->aliasSchemas[$name])) {
                $resolved = $this->resolveAlias($name);
                if ($resolved->isMap) {
                    return 'it is an object map, which has no flat query-string serialization';
                }
                if ($resolved->dataCollectionOf !== null) {
                    return 'it is an array of objects, which has no flat query-string serialization';
                }
            }

            // A generated enum, a scalar/array alias, or an unknown component
            // (degrades to mixed): all safe.
            return null;
        }

        if ($this->isPureMap($schema)) {
            return 'it is an object map, which has no flat query-string serialization';
        }

        $primary = $this->normalizeTypes($schema)[0] ?? null;

        if ($primary === 'object' || $this->notEmptyArray($schema->properties) || $this->notEmptyArray($schema->allOf)) {
            return 'it is an object, which has no flat query-string serialization';
        }

        if ($primary === 'array') {
            $items = $schema->items;
            if ($items instanceof Schema || $items instanceof Reference) {
                return $this->queryShapeSkipReason($items, $depth + 1);
            }
        }

        // Scalars, inline enums/consts, oneOf/anyOf unions (which resolve to a
        // native scalar union or degrade to presence-only mixed), and untyped
        // schemas are all safe for flat form serialization.
        return null;
    }

    /**
     * @return array<string, Schema>
     */
    private function componentSchemas(OpenApi $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $keep = $this->options->keepSchemas;

        $result = [];
        foreach ($components->schemas as $name => $schema) {
            // Subset generation (issue #44): when a keep-set is configured, emit
            // only its members. The caller closed the set over its transitive
            // dependencies, so every surviving $ref still resolves. A null
            // keep-set (the default) keeps every schema, byte-identical to before.
            if ($keep !== null && ! isset($keep[(string) $name])) {
                continue;
            }

            // Component entries that are bare $refs (aliases) are skipped in v1.
            // References to a skipped entry resolve to mixed, so the skip is
            // surfaced through the warnings channel instead of staying silent
            // (issue #67).
            if (! $schema instanceof Schema) {
                $this->warnings[$schema instanceof Reference
                    ? sprintf(
                        'Component schema "%s" is a bare $ref ("%s") and is not generated; references to it degrade to mixed with presence-only validation.',
                        (string) $name,
                        $schema->getReference(),
                    )
                    : sprintf(
                        'Component schema "%s" is not a schema object and is not generated; references to it degrade to mixed with presence-only validation.',
                        (string) $name,
                    )] = true;

                continue;
            }

            $result[(string) $name] = $schema;
        }

        return $result;
    }

    /**
     * @param  list<string>  $docIntro  class-level docblock lines emitted FIRST (the per-operation
     *                                  body classes lead with their operation, issue #76); the
     *                                  default empty list keeps every existing class byte-identical
     */
    private function emitData(string $className, Schema $schema, int $depth, string $variant = 'all', array $docIntro = []): void
    {
        $this->pushRefScope($className);

        $properties = $this->objectProperties($schema);
        $required = $this->requiredNames($schema);

        // A scalar component (no named properties) that is an enum PHP cannot
        // back as a native enum (a float enum) must still carry its constraint:
        // wrap it in a single `value` property typed by the scalar with the
        // `Rule::in` membership rule. Without this it would emit an empty,
        // useless Data class that silently accepts any value.
        if ($properties === [] && $this->isScalarEnumComponent($schema)) {
            $properties = ['value' => $schema];
            $required = ['value'];
        }

        $base = $this->stripSuffix($className);
        $propertyNames = new UniqueNames;

        $paramsRequired = [];
        $paramsOptional = [];
        $rules = [];
        $usesRule = false;

        // The declared wire (input) names, the allow-list for the closed-object
        // rule when `additionalProperties: false` enforcement is opted in.
        $declaredWireNames = [];

        // Wire name => nullable, for the properties THIS class declares (after
        // the read/write split). Drives the dependentRequired rule choice (#81).
        $declaredNullable = [];

        foreach ($properties as $rawName => $propertySchema) {
            // Numeric property names ("200") are coerced to int array keys by PHP.
            $wireName = (string) $rawName;

            // A non-standard per-property `required: true` (a boolean sitting on
            // the property schema itself) is ignored by OpenAPI 3.x, which only
            // honours the schema-level `required: [...]` array. We do not change
            // behavior (the field stays optional unless the array lists it), but
            // we record a diagnostic so the silent information loss is reported.
            $this->warnPerPropertyRequired($base, $wireName, $propertySchema);

            // readOnly fields are response-only (drop from the write variant);
            // writeOnly fields are request-only (drop from the read variant).
            if ($variant === 'read' && $this->isWriteOnly($propertySchema)) {
                continue;
            }
            if ($variant === 'write' && $this->isReadOnly($propertySchema)) {
                continue;
            }

            // Distinct wire names can collapse to the same identifier
            // (first_name + firstName); suffix collisions to avoid duplicate params.
            $propertyName = $propertyNames->reserve(PhpIdentifier::toPropertyName($wireName));
            $listedRequired = in_array($wireName, $required, true);

            // A multipart file part (issue #75): at the root of a
            // multipart/form-data body, a binary string property is an uploaded
            // file and an array of binary items a list of uploaded files. Only
            // root properties are form parts, so the detection is gated on
            // depth 0; a binary string nested deeper sits inside a
            // JSON-serialized part and keeps its plain string typing.
            $filePart = $this->multipartBody && $depth === 0 ? $this->multipartFilePart($propertySchema) : null;

            $type = $filePart !== null
                ? $this->multipartFileType($filePart)
                : $this->resolveType($propertySchema, $base.PhpIdentifier::toClassName($wireName), $depth + 1, $variant);
            $this->noteClassRef(...$type->classRefs);

            // A scalar `default` makes the property optional on input even when
            // the spec lists it as required: an omitted value is filled by the
            // default, so the input rule is `sometimes`, not `required`. The
            // default also seeds the constructor parameter (`= 5`) instead of the
            // hardcoded `= null`, and a property carrying a default never sits in
            // the required-parameter group (PHP defaulted params must come last).
            $default = $this->defaultValue($propertySchema, $type);
            $isRequired = $listedRequired && $default === null;

            $rendered = $this->renderProperty($wireName, $propertyName, $type, $isRequired, $default, $this->deprecationTag($propertySchema));

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            // Validation rules are keyed by the wire (mapped input) name. The
            // wildcard map carries one entry per array-nesting level ('.*',
            // '.*.*', ...) so a nested array enforces its inner item rules too.
            [$propertyRules, $wildcardRules, $uses] = $filePart !== null
                ? $this->multipartFileRules($filePart, $isRequired, $type)
                : $this->buildRules($propertySchema, $isRequired, $type);
            $rules[$wireName] = $propertyRules;
            foreach ($wildcardRules as $suffix => $ruleList) {
                if ($ruleList !== []) {
                    $rules[$wireName.$suffix] = $ruleList;
                }
            }
            $usesRule = $usesRule || $uses;
            $declaredWireNames[] = $wireName;
            $declaredNullable[$wireName] = $type->nullable;
        }

        // dependentRequired (issue #81): a property listed as a dependent of a
        // present trigger becomes conditionally required via required_with.
        $this->applyDependentRequired($base, $schema, $required, $properties, $declaredNullable, $rules);

        // Closed-object enforcement (issue #30). When enforceClosedObjects is on
        // (the default) and the schema declares additionalProperties: false,
        // emit a payload-wide rule that rejects any key outside the declared
        // set. The sentinel key never collides with a real wire name, and the
        // rule is implicit so it fires once even when absent. Opting out with
        // --no-enforce-closed-objects restores the lenient output. A schema
        // that also declares patternProperties widens the allow-set with its
        // patterns (issue #65); see closedObjectRule().
        if ($this->options->enforceClosedObjects && $this->declaresClosedObject($schema)) {
            $closedObjectRule = $this->closedObjectRule($base, $declaredWireNames, $schema);
            if ($closedObjectRule !== null) {
                $rules[self::CLOSED_OBJECT_SENTINEL] = [$closedObjectRule];
            }
        }

        $params = array_merge($paramsRequired, $paramsOptional);
        $imports = $this->collectImports($params, $usesRule, $rules);

        // An empty class body (no properties, no rules) compiles fine but
        // silently drops every payload field (issue #95). The rendered body
        // carries a marker comment (see EMPTY_BODY_MARKER); this surfaces the
        // same finding through the diagnostics channel, once per empty class,
        // naming the schema. Free-form maps and aliases never reach here (they
        // are inlined at use sites, not emitted as classes), and a closed empty
        // object carries the closed-object rule, so only a truly property-less
        // object schema (or a read/write variant whose every property was
        // dropped by the split) is flagged.
        if ($params === [] && $rules === []) {
            $detail = match ($variant) {
                'read' => 'no readable (non-writeOnly) properties',
                'write' => 'no writable (non-readOnly) properties',
                default => 'no properties',
            };
            $this->warnings[sprintf(
                'Schema "%s" has %s: the generated class "%s" has an empty body, so every payload field for this shape is dropped on hydration.',
                $base,
                $detail,
                $className,
            )] = true;
        }

        // Class-level docblock lines, in a stable order: a `@deprecated` tag for
        // a deprecated component first, then the additionalProperties overflow
        // note. A mixed object (named properties AND additionalProperties) emits
        // its named properties normally; laravel-data cannot route unknown keys
        // into a designated property without a custom cast, so the dynamic
        // overflow is documented, not silently dropped into a non-functional
        // field.
        $classDoc = $docIntro;
        $deprecationTag = $this->deprecationTag($schema);
        if ($deprecationTag !== null) {
            $classDoc[] = $deprecationTag;
        }
        if ($this->notEmptyArray($properties) && $this->additionalPropertiesSchema($schema) !== null) {
            $classDoc[] = 'This schema also declares additionalProperties: dynamic keys beyond the named properties above are not captured by this class.';
        }

        // Close this class's reference scope and import every cross-group
        // reference (issue #93); a flat-layout run records groups of null
        // everywhere, so the imports come back unchanged.
        $imports = $this->withCrossGroupImports($className, $imports, $this->popRefScope());

        $this->files[$className] = new GeneratedFile(
            $className,
            $this->renderDataClass($className, $params, $imports, $rules, $classDoc),
            $this->fileGroups[$className] ?? null,
        );
    }

    /**
     * Emit the abstract base of a discriminated union. The base carries ONLY the
     * discriminator property (marked `#[PropertyForMorph]`, with its rules) and a
     * `morph()` that maps each discriminator value to its variant Data class.
     * spatie/laravel-data reads the discriminator from the payload, calls morph()
     * to pick the concrete variant, and then validates and hydrates THAT variant,
     * so per-variant validation and polymorphic hydration both come for free.
     */
    private function emitDiscriminatorBase(string $baseName, string $className): void
    {
        $this->pushRefScope($className);

        $wireName = (string) $this->discriminators->propertyName($baseName);
        $propertyName = PhpIdentifier::toPropertyName($wireName);

        // The discriminator type comes from a variant that declares it (every
        // variant shares the discriminator property). Default to string when no
        // variant types it explicitly: a discriminator is a string in practice.
        $type = $this->discriminatorType($baseName, $className);

        // The discriminator's rules are emitted as VALIDATION ATTRIBUTES, not a
        // rules() method. spatie adds its morph guard (EnsurePropertyMorphable)
        // to the discriminator property during rule inference; a rules() method
        // on the base sets hasDynamicValidationRules, whose overwritten-rules
        // pass REPLACES the inferred rules for the discriminator key, silently
        // dropping the morph guard so an unmapped value would pass. Attributes
        // stay on the inference path, preserving the guard, exactly as the design
        // spike proved. The discriminator is always required (it selects the
        // variant); a type attribute matches the resolved scalar.
        $validationAttributes = ['Required'];
        $validationImports = ['Spatie\\LaravelData\\Attributes\\Validation\\Required'];
        $typeAttribute = $this->discriminatorTypeAttribute($type);
        if ($typeAttribute !== null) {
            $validationAttributes[] = $typeAttribute;
            $validationImports[] = 'Spatie\\LaravelData\\Attributes\\Validation\\'.$typeAttribute;
        }

        $imports = array_merge([
            'Spatie\\LaravelData\\Data',
            'Spatie\\LaravelData\\Contracts\\PropertyMorphableData',
            'Spatie\\LaravelData\\Attributes\\PropertyForMorph',
        ], $validationImports);

        $mapName = '';
        if (PhpIdentifier::needsMapName($wireName, $propertyName)) {
            $mapName = "        #[MapName('".$this->escapeSingleQuoted($wireName)."')]\n";
            $imports[] = 'Spatie\\LaravelData\\Attributes\\MapName';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        // match() arm per discriminator value -> variant Data class, sorted by
        // value for determinism, with a `default => null` so an unmapped value
        // stays abstract and is rejected by spatie's morph guard. The arm literal
        // is typed by the discriminator: for an int discriminator the payload
        // value arrives as an int, and match is strict, so the arm must be an int
        // literal (1 not '1') or it would never compare equal.
        $arms = [];
        foreach ($this->discriminators->valueToVariant($baseName) as $value => $variantSchemaName) {
            $variantClass = $this->registry[$variantSchemaName]['class'] ?? null;
            if ($variantClass === null) {
                continue;
            }
            $this->noteClassRef($variantClass);
            $arms[] = '            '.$this->discriminatorValueLiteral((string) $value, $type).' => '.$variantClass.'::class,';
        }
        $arms[] = '            default => null,';

        // A deprecated discriminated base carries a class-level `@deprecated`.
        $baseSchema = $this->registry[$baseName]['schema'] ?? null;
        $deprecationTag = $baseSchema instanceof Schema ? $this->deprecationTag($baseSchema) : null;

        // A variant may live in another tag group (issue #93): the morph()
        // arms reference it by short name, so import it from its real group.
        $imports = $this->withCrossGroupImports($className, $imports, $this->popRefScope());

        $this->files[$className] = new GeneratedFile(
            $className,
            $this->renderDiscriminatorBase($className, $imports, $mapName, $propertyName, $type, $validationAttributes, $arms, $deprecationTag),
            $this->fileGroups[$className] ?? null,
        );
    }

    /**
     * The spatie validation type attribute for a discriminator's resolved scalar
     * type (`StringType`/`IntegerType`), or null when the type is neither (a
     * float/bool/union discriminator, which is unusual: it then carries only the
     * `Required` attribute and no type assertion).
     */
    private function discriminatorTypeAttribute(ResolvedType $type): ?string
    {
        return match ($type->declaration) {
            'string' => 'StringType',
            'int' => 'IntegerType',
            default => null,
        };
    }

    /**
     * Render a discriminator mapping value as a PHP literal for a morph() match
     * arm, typed by the discriminator's resolved type. OpenAPI mapping keys (and
     * implicit schema-name values) are always strings, but for an INT
     * discriminator the runtime payload value is an int and `match` compares
     * strictly, so a numeric value must be emitted as an int literal (1, not '1')
     * to compare equal. A non-numeric value under an int discriminator (a
     * malformed mapping) falls back to a string literal so the arm still compiles.
     */
    private function discriminatorValueLiteral(string $value, ResolvedType $type): string
    {
        if ($type->declaration === 'int' && preg_match('/^-?\d+$/', $value) === 1) {
            return $this->numberLiteral((int) $value);
        }

        return $this->scalarLiteral($value);
    }

    /**
     * Emit a variant of a discriminated union. It extends the abstract base,
     * forwards the discriminator to the parent constructor (a non-promoted param
     * so the base owns the single declared discriminator property), and declares
     * its OWN remaining properties as promoted readonly params with their rules.
     */
    private function emitVariant(string $variantName, string $className, Schema $schema): void
    {
        $this->pushRefScope($className);

        $baseName = (string) $this->discriminators->baseOf($variantName);
        $baseClass = $this->registry[$baseName]['class'] ?? 'Data';
        // The `extends` references the base by short name; under the grouped
        // layout (issue #93) the base may live in another group, so record the
        // reference (the defensive 'Data' fallback is never a generated class
        // and is filtered by withCrossGroupImports).
        $this->noteClassRef($baseClass);
        $discriminatorWire = (string) $this->discriminators->propertyName($baseName);
        $discriminatorProperty = PhpIdentifier::toPropertyName($discriminatorWire);

        // Forward the discriminator with the SAME type the base declares it with
        // (string or int), or the parent::__construct() call type-mismatches the
        // base property under strict_types. Resolved off the base so it is
        // identical to the base's own property declaration.
        $discriminatorDeclaration = $this->discriminatorType($baseName, $baseClass)->declaration();

        $properties = $this->objectProperties($schema);
        $required = $this->requiredNames($schema);

        // For the allOf-inheritance form (#38) the variant's allOf composes the
        // base via `$ref`, so objectProperties() above merged the base's OWN
        // properties into the variant too. The base abstract class declares ONLY
        // the discriminator (the morph routing property), so a variant redeclares
        // every other property (its own plus any base-shared field) as a promoted
        // readonly param. Only the discriminator is forwarded to the parent. This
        // keeps the named-component and inline forms unchanged (their base also
        // declares only the discriminator) and gives the allOf form correct
        // per-variant validation of the full merged shape.
        $base = $this->stripSuffix($className);
        $propertyNames = new UniqueNames;
        // The discriminator property name is owned by the base; reserve it so a
        // variant's own property never collides with the forwarded param.
        $propertyNames->reserve($discriminatorProperty);

        $paramsRequired = [];
        $paramsOptional = [];
        $rules = [];
        $usesRule = false;

        // Wire name => nullable, for the properties THIS variant declares
        // (the discriminator stays on the base). Drives dependentRequired (#81).
        $declaredNullable = [];

        foreach ($properties as $rawName => $propertySchema) {
            $wireName = (string) $rawName;

            // The discriminator property lives on the base, so the variant never
            // redeclares it. But when this variant pins its own discriminator
            // value via a `const` (or a single-value enum), emit a membership rule
            // for the inherited discriminator key so validating this variant
            // STANDALONE rejects a payload whose discriminator does not match this
            // variant's value. Routing via the morph base is unaffected: morph
            // selects the variant by the discriminator first, so this variant's
            // rules() only runs once the value already matches.
            if ($wireName === $discriminatorWire) {
                $pinned = $this->discriminatorConstRule($propertySchema);
                if ($pinned !== null) {
                    $rules[$wireName] = $pinned;
                    $usesRule = true;
                }

                continue;
            }

            $this->warnPerPropertyRequired($base, $wireName, $propertySchema);

            $propertyName = $propertyNames->reserve(PhpIdentifier::toPropertyName($wireName));
            $listedRequired = in_array($wireName, $required, true);
            $type = $this->resolveType($propertySchema, $base.PhpIdentifier::toClassName($wireName), 1);
            $this->noteClassRef(...$type->classRefs);

            $default = $this->defaultValue($propertySchema, $type);
            $isRequired = $listedRequired && $default === null;

            $rendered = $this->renderProperty($wireName, $propertyName, $type, $isRequired, $default, $this->deprecationTag($propertySchema));

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            [$propertyRules, $wildcardRules, $uses] = $this->buildRules($propertySchema, $isRequired, $type);
            $rules[$wireName] = $propertyRules;
            foreach ($wildcardRules as $suffix => $ruleList) {
                if ($ruleList !== []) {
                    $rules[$wireName.$suffix] = $ruleList;
                }
            }
            $usesRule = $usesRule || $uses;
            $declaredNullable[$wireName] = $type->nullable;
        }

        // dependentRequired (issue #81), merged across the variant's allOf
        // members. A dependent that is the discriminator itself is skipped via
        // the declared map (the base owns and already requires that property).
        $this->applyDependentRequired($base, $schema, $required, $properties, $declaredNullable, $rules);

        $params = array_merge($paramsRequired, $paramsOptional);

        // The variant extends the base, so it no longer needs the Spatie Data
        // import. The base lives in the same namespace as the variant, so it is
        // referenced by short name with no `use` (a same-namespace import would
        // be redundant and Pint would strip it).
        $imports = $this->collectImports($params, $usesRule, $rules);
        $imports = array_values(array_filter($imports, static fn (string $i): bool => $i !== 'Spatie\\LaravelData\\Data'));
        sort($imports);

        // Under the grouped layout (issue #93) the base or a referenced class
        // may live in another group; import those from their real namespaces.
        $imports = $this->withCrossGroupImports($className, $imports, $this->popRefScope());

        $this->files[$className] = new GeneratedFile(
            $className,
            $this->renderVariantClass($className, $baseClass, $discriminatorProperty, $discriminatorDeclaration, $params, $imports, $rules, $this->deprecationTag($schema)),
            $this->fileGroups[$className] ?? null,
        );
    }

    /**
     * The resolved PHP type of a discriminated union's discriminator property.
     * Both the abstract base (which declares the property) and each variant
     * (which forwards it as a non-promoted parameter) must use the SAME type, or
     * the forwarded parameter type mismatches the base property under
     * strict_types and constructing a variant throws a TypeError. Derived from a
     * variant's declared schema; defaults to `string` when no variant types it.
     */
    private function discriminatorType(string $baseName, string $className): ResolvedType
    {
        $wireName = (string) $this->discriminators->propertyName($baseName);
        $schema = $this->discriminatorPropertySchema($baseName, $wireName);

        if ($schema === null) {
            return new ResolvedType('string');
        }

        return $this->resolveType($schema, $this->stripSuffix($className).PhpIdentifier::toClassName($wireName), 1);
    }

    /**
     * The membership rule pinning a variant's own discriminator value, or null
     * when the variant does not constrain it. A discriminated-union variant often
     * declares its discriminator with a `const` (`kind: {const: alpha}`) or a
     * single-value enum (`kind: {enum: [alpha]}`); that fixes the discriminator
     * to one value for this variant. The base only enforces the discriminator
     * presence-only (it must morph across all values), so without this a variant
     * validated standalone would accept any discriminator value. The rule is a
     * single `Rule::in([value])`, reusing the enum/const literal escaping.
     *
     * @return list<string>|null
     */
    private function discriminatorConstRule(Schema|Reference $schema): ?array
    {
        if (! $schema instanceof Schema) {
            return null;
        }

        $const = $this->constValue($schema);
        if ($const !== null) {
            return ['Rule::in(['.$this->scalarLiteral($const[0]).'])'];
        }

        // A single-value enum pins the value just like a const.
        $values = $this->enumValues($schema);
        if (count($values) === 1) {
            return ['Rule::in(['.$this->scalarLiteral($values[0]).'])'];
        }

        return null;
    }

    /**
     * The schema of the discriminator property, taken from the first variant
     * (sorted) that declares it. All variants share the discriminator, so any
     * one is representative. Returns null when no variant types it.
     */
    private function discriminatorPropertySchema(string $baseName, string $wireName): ?Schema
    {
        foreach ($this->discriminators->variants($baseName) as $variantSchemaName) {
            $schema = $this->registry[$variantSchemaName]['schema'] ?? null;
            if ($schema === null) {
                continue;
            }
            $property = $this->objectProperties($schema)[$wireName] ?? null;
            if ($property instanceof Schema) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Support-rule classes referenced by short name inside emitted rule
     * expressions (`new MultipleOfRule(...)`, `new Rfc3339DateTimeRule`). When a
     * rule string references one, its FQCN is resolved against the consumer's own
     * Support namespace and the class is recorded for inlining (issue #40).
     */
    private const RULE_CLASS_NAMES = [
        'HostnameRule',
        'Iso8601DurationRule',
        'MultipleOfRule',
        'NoUnknownPropertiesRule',
        'Rfc3339DateTimeRule',
        'Rfc3339TimeRule',
    ];

    /**
     * The map transformer short name. A map-typed property gets a
     * `#[WithTransformer(MapObjectTransformer::class)]` attribute plus an import
     * of this class, resolved (like the rule classes) against the consumer's own
     * Support namespace so generated output owns it (issue #40).
     */
    private const MAP_TRANSFORMER = 'MapObjectTransformer';

    /**
     * The rules() key under which the opt-in closed-object rule is attached. It
     * is namespaced with a leading marker so it can never collide with a real
     * wire (input) name (OpenAPI property names do not begin with this prefix in
     * practice, and even if one did, the implicit rule keyed here is harmless).
     */
    private const CLOSED_OBJECT_SENTINEL = '__openapi_laravel_no_unknown_properties';

    /**
     * The comment rendered inside an empty Data class body (issue #95). An empty
     * class compiles fine but silently drops every payload field, so the gap is
     * made visible in the generated code itself (a build warning naming the
     * schema accompanies it). The comment also keeps the body non-empty, which
     * keeps Pint's `single_line_empty_body` fixer away from it.
     */
    private const EMPTY_BODY_MARKER = '// The spec defines no properties for this schema.';

    /**
     * The import FQCN for a runtime support class, resolved against the
     * consumer's own Support namespace (issue #40), and recorded as used so it is
     * inlined into the consumer's output. Generated code therefore imports its
     * rules and transformer from `<DataNamespace>\Support`, never from the
     * generator package, leaving the output self-contained.
     */
    private function supportImport(string $shortName): string
    {
        $this->usedSupportClasses[$shortName] = true;

        return $this->options->supportNamespace().'\\'.$shortName;
    }

    /**
     * Record a runtime support class as used by the server scaffold, so it is
     * inlined into the consumer's Support namespace by supportFiles(). The
     * model pipeline records its own uses internally (supportImport()); this
     * is the entry point for the operation collector, whose routes-file
     * middleware (issue #64) references `<DataNamespace>\Support` classes the
     * model generator never sees.
     */
    public function markSupportClassUsed(string $shortName): void
    {
        $this->usedSupportClasses[$shortName] = true;
    }

    /**
     * The inlined runtime support classes for the last generate() run (issue
     * #40), keyed and ordered by class name. Only the classes the run actually
     * referenced are present, each rendered into the consumer's Support namespace
     * from its canonical `src/Support/X.php` template. These are owned, drift-
     * checked output: the planner writes them into `<output>/Support/` and the
     * check command compares them byte-for-byte like every other generated file.
     *
     * Exposed as a getter (not folded into the generate() return) so the existing
     * Data-class callers keep compiling unchanged: a caller that needs the support
     * set opts in by calling this after generate(), mirroring warnings().
     *
     * @return array<string, GeneratedFile>
     */
    public function supportFiles(): array
    {
        $emitter = new SupportClassEmitter($this->options->supportNamespace());

        $files = [];
        foreach (array_keys($this->usedSupportClasses) as $shortName) {
            $files[$shortName] = $emitter->emit($shortName);
        }

        ksort($files);

        return $files;
    }

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
    private function groupForSchema(string $schemaName): ?string
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
     * The tag group a per-operation class (query, issue #63; body, issue #76)
     * belongs to: its operation's tag group, which is unambiguous because an
     * operation has exactly one controller tag. Null for a tagless caller or
     * for the reserved 'Support' group.
     */
    private function groupForTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return TagGroups::forTag($tag);
    }

    /**
     * The namespace a generated class is emitted into: the configured Data
     * namespace, extended by the class's tag group under the grouped layout
     * (issue #93). Public so the server scaffold imports Data classes from
     * the namespace they actually land in.
     */
    public function namespaceFor(string $className): string
    {
        $group = $this->fileGroups[$className] ?? null;

        return $group === null ? $this->options->namespace : $this->options->namespace.'\\'.$group;
    }

    /**
     * Open a reference scope for a class being emitted (issue #93). Scopes
     * stack because emission is reentrant: an inline object property spawns a
     * nested class in the middle of its holder's emission. The class's group
     * must already be assigned in $fileGroups when the scope opens.
     */
    private function pushRefScope(string $className): void
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
    private function pushGroupScope(?string $group): void
    {
        $this->refScopes[] = ['group' => $group, 'refs' => []];
    }

    /**
     * Close the current reference scope and return the generated classes it
     * recorded, sorted for deterministic import order.
     *
     * @return list<string>
     */
    private function popRefScope(): array
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
    private function noteClassRef(string ...$classes): void
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
    private function currentScopeGroup(): ?string
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
    private function withCrossGroupImports(string $className, array $imports, array $refs): array
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
     * @param  list<array{code: string, imports: list<string>}>  $params
     * @param  array<string, list<string>>  $rules
     * @return list<string>
     */
    private function collectImports(array $params, bool $usesRule, array $rules): array
    {
        $imports = ['Spatie\\LaravelData\\Data'];

        foreach ($params as $param) {
            foreach ($param['imports'] as $import) {
                $imports[] = $import;
            }
        }

        if ($usesRule) {
            $imports[] = 'Illuminate\\Validation\\Rule';
        }

        // A custom Rule class (`new MultipleOfRule(...)`) appears as a bare short
        // name in the rule expression; import its FQCN so the reference resolves.
        // The FQCN points at the consumer's own Support namespace (issue #40), and
        // the class is recorded so it is inlined into the consumer's output.
        $ruleText = '';
        foreach ($rules as $expressions) {
            $ruleText .= implode(' ', $expressions).' ';
        }
        foreach (self::RULE_CLASS_NAMES as $shortName) {
            if (str_contains($ruleText, 'new '.$shortName)) {
                $imports[] = $this->supportImport($shortName);
            }
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $imports;
    }

    /**
     * @param  array{0: string}|null  $default  the rendered scalar default expression, wrapped, or null for "no default"
     * @param  ?string  $deprecationTag  the `@deprecated ...` line for a deprecated property, or null
     * @return array{code: string, imports: list<string>}
     */
    private function renderProperty(string $wireName, string $propertyName, ResolvedType $type, bool $isRequired, ?array $default = null, ?string $deprecationTag = null): array
    {
        $imports = $type->imports;
        $lines = [];

        // Per-property docblock. A `@var` (the richer array/union generic) and a
        // `@deprecated` tag both ride here. With only one tag the block stays a
        // single line; with both it expands to a multi-line block, `@var` first
        // then `@deprecated`, for a stable order.
        $docTags = [];
        if ($type->docType !== null) {
            $docTags[] = '@var '.$type->docType;
        }
        if ($deprecationTag !== null) {
            $docTags[] = $deprecationTag;
        }
        if (count($docTags) === 1) {
            $lines[] = '        /** '.$docTags[0].' */';
        } elseif (count($docTags) > 1) {
            $lines[] = '        /**';
            // `@var` and `@deprecated` are distinct annotation groups, so the
            // Laravel Pint `phpdoc_separation` fixer wants a blank ` *` line
            // between them. Emit that separator up front so the docblock is
            // born-clean and the output stays formatter-idempotent.
            $lines[] = '         * '.$docTags[0];
            foreach (array_slice($docTags, 1) as $tag) {
                $lines[] = '         *';
                $lines[] = '         * '.$tag;
            }
            $lines[] = '         */';
        }

        if ($type->dataCollectionOf !== null) {
            $imports[] = 'Spatie\\LaravelData\\Attributes\\DataCollectionOf';
            $lines[] = '        #[DataCollectionOf('.$type->dataCollectionOf.'::class)]';
        }

        // A map (`array<string, X>`) serializes its empty form as `[]` unless a
        // transformer casts it to an object. Attach one so an empty map emits the
        // JSON object `{}` strict clients expect; non-empty maps and null are
        // unaffected.
        if ($type->isMap) {
            $imports[] = 'Spatie\\LaravelData\\Attributes\\WithTransformer';
            $imports[] = $this->supportImport(self::MAP_TRANSFORMER);
            $lines[] = '        #[WithTransformer(MapObjectTransformer::class)]';
        }

        if (PhpIdentifier::needsMapName($wireName, $propertyName)) {
            $imports[] = 'Spatie\\LaravelData\\Attributes\\MapName';
            $lines[] = "        #[MapName('".$this->escapeSingleQuoted($wireName)."')]";
        }

        if ($isRequired) {
            $declaration = $type->declaration();
            $defaultExpr = '';
        } elseif ($default !== null) {
            // A scalar default seeds the parameter. The declaration stays non-null
            // when the schema is not nullable (the default makes null impossible);
            // a nullable schema keeps its nullable declaration.
            $declaration = $type->nullable ? $this->optionalDeclaration($type) : $type->declaration;
            $defaultExpr = ' = '.$default[0];
        } else {
            $declaration = $this->optionalDeclaration($type);
            $defaultExpr = ' = null';
        }

        $lines[] = '        public readonly '.$declaration.' $'.$propertyName.$defaultExpr.',';

        return ['code' => implode("\n", $lines), 'imports' => array_values($imports)];
    }

    /**
     * The scalar `default` of a property, rendered as a PHP literal expression
     * wrapped in a single-element list, or null when there is no usable default.
     *
     * Only a scalar default (string/int/float/bool) on a scalar-typed property is
     * emitted: the constructor parameter must accept the literal, so a default on
     * an enum-typed, Data-class-typed, array-typed, or `mixed` property is skipped
     * (it keeps the `= null` default and is still optional). The schema's own
     * `getSerializableData()` is read so an explicit `default: null`/`false`/`0`
     * is distinguished from "no default at all".
     *
     * @return array{0: string}|null
     */
    private function defaultValue(Schema|Reference $schema, ResolvedType $type): ?array
    {
        if (! $schema instanceof Schema) {
            return null;
        }

        $serialized = (array) $schema->getSerializableData();
        if (! array_key_exists('default', $serialized)) {
            return null;
        }

        $value = $serialized['default'];

        // The parameter type must be able to hold the literal: only emit a scalar
        // default on a scalar (or scalar-union) declaration. Enum/Data-class/array
        // and `mixed` declarations keep the null default.
        if (! $this->isScalarDeclaration($type)) {
            return null;
        }

        // The literal's PHP type must also match a member of the declared type.
        // Specs routinely carry a mistyped default (xero gives a `bool` property
        // the string `"false"`); emitting it verbatim produces `bool $x = 'false'`,
        // a fatal "Cannot use string as default value". When the type does not
        // match, fall through to the `= null`/optional default instead.
        $members = $this->scalarMembers($type);

        if (is_bool($value)) {
            return in_array('bool', $members, true) ? [$value ? 'true' : 'false'] : null;
        }

        if (is_int($value) || is_float($value)) {
            // An int literal also satisfies a `float` parameter (PHP widens it);
            // a float literal needs a `float` member.
            $accepts = is_int($value)
                ? (in_array('int', $members, true) || in_array('float', $members, true))
                : in_array('float', $members, true);

            return $accepts ? [$this->numberLiteral($value)] : null;
        }

        if (is_string($value)) {
            return in_array('string', $members, true)
                ? ["'".$this->escapeSingleQuoted($value)."'"]
                : null;
        }

        // An array/object/null default is not a scalar literal we seed here.
        return null;
    }

    /**
     * The PHP scalar member names of a resolved type's declaration (`?bool` ->
     * `['bool']`, `string|int|null` -> `['string', 'int']`). Used to confirm a
     * default literal's type matches the parameter before seeding it.
     *
     * @return list<string>
     */
    private function scalarMembers(ResolvedType $type): array
    {
        $members = [];

        foreach (explode('|', $type->declaration) as $member) {
            $member = ltrim($member, '?');
            if ($member === 'null' || $member === '') {
                continue;
            }
            $members[] = $member;
        }

        return $members;
    }

    /**
     * Whether a resolved type is a scalar (or a union of scalars), so a scalar
     * literal is a valid constructor default for it. A union built from scalars
     * (`string|int`) qualifies; a union or single type that names a class
     * (`CustomerStatus`, `TagData`) does not.
     */
    private function isScalarDeclaration(ResolvedType $type): bool
    {
        $members = explode('|', $type->declaration);

        foreach ($members as $member) {
            $member = ltrim($member, '?');
            if ($member === 'null') {
                continue;
            }
            if (! in_array($member, self::SCALARS, true)) {
                return false;
            }
        }

        return true;
    }

    private function optionalDeclaration(ResolvedType $type): string
    {
        if ($type->declaration === 'mixed') {
            return 'mixed';
        }

        // An optional property defaults to null, so the type must accept null. A
        // genuine multi-member union spells that as a trailing `|null` member
        // ('string|int|null'), never the `?` shorthand, which PHP forbids on a
        // union. A single type (including a degenerate one-member union, a `oneOf`
        // of one scalar) uses `?T`: PHP allows it and the Pint preset normalizes
        // `T|null` to it anyway, so emitting `?T` keeps the output idempotent.
        if ($type->isMultiMemberUnion()) {
            return str_ends_with($type->declaration, '|null') ? $type->declaration : $type->declaration.'|null';
        }

        return '?'.$type->declaration;
    }

    /**
     * Derive Laravel validation rules for a property from its schema.
     *
     * The second element is a wildcard-rule map keyed by suffix relative to the
     * property name: '.*' for an array's items, '.*.*' for a nested array's
     * inner items, and so on. An empty map means the property has no item rules.
     *
     * @return array{0: list<string>, 1: array<string, list<string>>, 2: bool} property rules,
     *                                                                         wildcard item rules keyed by suffix, whether Rule:: is used
     */
    private function buildRules(Schema|Reference $schema, bool $required, ResolvedType $type): array
    {
        $rules = $this->presenceRules($required, $type->nullable);

        if ($schema instanceof Reference) {
            // A $ref to a pure-map component: the value is a typed array, so the
            // property rule is 'array' plus the map's own key-count bounds
            // (minProperties/maxProperties, issue #72) and a wildcard value rule
            // derived from the map's value schema.
            $mapSchema = $this->referencedMapSchema($schema);
            if ($mapSchema !== null) {
                [$valueRules, $valueUses] = $this->mapValueRules($mapSchema);

                return [array_merge($rules, ["'array'"], $this->objectCountRules($mapSchema)), $this->wildcardMap($valueRules), $valueUses];
            }

            // A $ref to a non-object alias component (scalar/array/union): derive
            // the rules from the underlying alias schema, not the empty class, so
            // a date-time alias still emits its date-time rule, a length-bounded
            // string alias its max:/min:, an array alias its 'array' + item
            // rules, and a union alias its presence-only rules. A chained alias
            // (allOf-ref -> scalar) is followed to its terminal schema first so
            // the constraint at the end of the chain is not lost.
            $aliasSchema = $this->referencedAliasSchema($schema);
            if ($aliasSchema !== null) {
                $terminal = $this->terminalAliasSchema($aliasSchema);

                return $this->buildRules($terminal, $required, $type);
            }

            $enumClass = $this->referencedEnumClass($schema);
            if ($enumClass !== null) {
                $rules[] = 'Rule::enum('.$enumClass.'::class)';

                return [$rules, [], true];
            }

            // A $ref to an explicit `type: object` component that bounds its own
            // key count (issue #72): the nested Data class carries the body
            // rules, but minProperties/maxProperties constrain THIS value's key
            // count, and the use site is the only place a per-field rule can see
            // the value. Guarded on the explicit object type so an untyped
            // component (whose instances may legally be non-objects) is never
            // measured by string length or numeric value.
            $objectSchema = $this->referencedObjectSchema($schema);
            if ($objectSchema !== null && ($this->normalizeTypes($objectSchema)[0] ?? null) === 'object') {
                $countRules = $this->objectCountRules($objectSchema);
                if ($countRules !== []) {
                    return [array_merge($rules, ["'array'"], $countRules), [], false];
                }
            }

            return [$rules, [], false];
        }

        // A pure-map property: 'array' plus the map's own key-count bounds
        // (minProperties/maxProperties, issue #72) and a wildcard value rule.
        if ($this->isPureMap($schema)) {
            [$valueRules, $valueUses] = $this->mapValueRules($schema);

            return [array_merge($rules, ["'array'"], $this->objectCountRules($schema)), $this->wildcardMap($valueRules), $valueUses];
        }

        // oneOf/anyOf stay presence-only (no variant enforcement). allOf is an
        // object shape after merging, so it also gets presence-only rules here;
        // its member properties carry their own rules in the nested Data class.
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf) || $this->notEmptyArray($schema->allOf)) {
            return [$rules, [], false];
        }

        if ($this->notEmptyArray($schema->enum)) {
            $values = $this->enumValues($schema);
            if ($values !== []) {
                $rules[] = 'Rule::in(['.implode(', ', array_map(fn (string|int|float|bool $value): string => $this->scalarLiteral($value), $values)).'])';

                return [$rules, [], true];
            }
        }

        // `const` is a single-value enum: enforce the one allowed value with
        // Rule::in, reusing the enum machinery and scalar-literal escaping.
        $const = $this->constValue($schema);
        if ($const !== null) {
            $rules[] = 'Rule::in(['.$this->scalarLiteral($const[0]).'])';

            return [$rules, [], true];
        }

        $types = $this->normalizeTypes($schema);

        // A multi-type union (`type: ["string", "integer"]`) stays presence-only:
        // a single type rule (`'string'`) would wrongly reject the other valid
        // members. This mirrors the presence-only handling of oneOf/anyOf unions.
        if (count($types) > 1) {
            return [$rules, [], false];
        }

        $primary = $types[0] ?? null;

        if ($primary === 'string') {
            return [array_merge($rules, $this->stringRules($schema)), [], false];
        }

        if ($primary === 'integer') {
            return [array_merge($rules, ["'integer'"], $this->numericRules($schema)), [], false];
        }

        if ($primary === 'number') {
            return [array_merge($rules, ["'numeric'"], $this->numericRules($schema)), [], false];
        }

        if ($primary === 'boolean') {
            return [array_merge($rules, ["'boolean'"]), [], false];
        }

        if ($primary === 'array') {
            // A tuple (3.1 `prefixItems`, issue #82): Laravel addresses tuple
            // positions directly (`field.0`, `field.1`), so each position gets
            // the rules its schema pins. The post-prefix `items` schema is NOT
            // enforced (a `field.*` rule would also hit the prefix positions
            // and false-reject valid tuples); `uniqueItems` still applies to
            // every element, so its `distinct` wildcard is kept. The closed
            // form (`items: false`) arrives here as a synthesized `maxItems`
            // (see SchemaNormalizer) and lands in the count rules.
            $prefixes = $this->prefixItemSchemas($schema);
            if ($prefixes !== []) {
                [$indexed, $indexUses] = $this->prefixItemRules($prefixes);
                if ($schema->uniqueItems === true) {
                    $indexed['.*'] = ["'distinct'"];
                }

                return [array_merge($rules, ["'array'"], $this->arrayCountRules($schema)), $indexed, $indexUses];
            }

            [$wildcards, $itemUses] = $this->arrayWildcardRules($schema, '.*');

            return [array_merge($rules, ["'array'"], $this->arrayCountRules($schema)), $wildcards, $itemUses];
        }

        // An explicit inline `type: object` property (a nested Data class
        // shape): its body rules live in the nested class, but
        // minProperties/maxProperties constrain THIS value's key count
        // (issue #72), so they are emitted here together with the 'array'
        // shape assertion. Without count bounds the output stays presence-only,
        // byte-identical to before. An untyped object-ish schema is skipped:
        // its instances may legally be non-objects, where Laravel's min:/max:
        // would false-reject valid data (see objectCountRules()).
        if ($primary === 'object') {
            $countRules = $this->objectCountRules($schema);
            if ($countRules !== []) {
                return [array_merge($rules, ["'array'"], $countRules), [], false];
            }
        }

        return [$rules, [], false];
    }

    /**
     * Wrap a single list of item rules into the wildcard-rule map shape keyed
     * by '.*'. An empty or null list yields an empty map.
     *
     * @param  ?list<string>  $itemRules
     * @return array<string, list<string>>
     */
    private function wildcardMap(?array $itemRules): array
    {
        return $itemRules === null || $itemRules === [] ? [] : ['.*' => $itemRules];
    }

    /**
     * Wildcard rules for an array property, keyed by their suffix relative to
     * the property name ('.*', '.*.*', ...). Walks nested `array` items so an
     * array-of-array enforces an `array` rule at each level and the scalar item
     * rules at the leaf, plus a `distinct` rule wherever `uniqueItems` is set.
     * Without this recursion a nested array would drop its inner item rules
     * entirely and silently accept invalid inner values.
     *
     * @return array{0: array<string, list<string>>, 1: bool} wildcard rules by suffix, whether Rule:: is used
     */
    private function arrayWildcardRules(Schema $schema, string $suffix): array
    {
        $items = $schema->items;
        $map = [];

        if ($items instanceof Schema && ($this->normalizeTypes($items)[0] ?? null) === 'array') {
            // Each element at this level is itself an array: assert its shape and
            // count, then recurse to emit the inner level's rules ('.*.*', ...).
            $here = array_merge(["'array'"], $this->arrayCountRules($items));
            [$map, $uses] = $this->arrayWildcardRules($items, $suffix.'.*');
        } else {
            [$leaf, $uses] = $this->itemRules($schema);
            $here = $leaf ?? [];
        }

        // `uniqueItems: true` requires this array's direct elements (at $suffix)
        // to be distinct. Laravel expresses that with a `distinct` rule on the
        // wildcard, so append it to this level's element rules.
        if ($schema->uniqueItems === true) {
            $here = array_merge($here, ["'distinct'"]);
        }

        if ($here !== []) {
            $map = array_merge([$suffix => $here], $map);
        }

        return [$map, $uses];
    }

    /**
     * @return list<string>
     */
    private function presenceRules(bool $required, bool $nullable): array
    {
        if ($required && $nullable) {
            return ["'present'", "'nullable'"];
        }

        if ($required) {
            return ["'required'"];
        }

        if ($nullable) {
            return ["'sometimes'", "'nullable'"];
        }

        return ["'sometimes'"];
    }

    /**
     * Apply `dependentRequired` (issue #81) to the rules map: a property listed
     * as a dependent of a trigger becomes conditionally required via Laravel's
     * `required_with:<triggers>`, or `present_with:` when the dependent is
     * nullable, mirroring the required/present split of presenceRules() so a
     * spec-valid present null is not falsely rejected. A dependent required by
     * several triggers merges them into ONE rule, which matches JSON Schema
     * semantics: each trigger independently requires the dependent, and
     * required_with fires when ANY listed field is present.
     *
     * The dependency rule replaces a leading `sometimes`: `sometimes` skips the
     * whole rule list when the key is absent, which would silence the
     * conditional requirement exactly when it must fire (required_with is an
     * implicit rule that has to run against the absent key).
     *
     * @param  list<string>  $required  spec-required wire names (allOf-merged)
     * @param  array<string, Schema|Reference>  $properties  the full merged property map
     * @param  array<string, bool>  $declaredNullable  wire name => nullable, for the properties THIS class declares
     * @param  array<string, list<string>>  $rules
     */
    private function applyDependentRequired(string $base, Schema $schema, array $required, array $properties, array $declaredNullable, array &$rules): void
    {
        $triggersByDependent = [];
        foreach ($this->mergedDependentRequired($schema) as $trigger => $dependents) {
            foreach ($dependents as $dependent) {
                $triggersByDependent[$dependent][] = $trigger;
            }
        }

        foreach ($triggersByDependent as $dependent => $triggers) {
            // PHP coerces a numeric array key ("200") to int; rules are keyed
            // by the wire (string) name.
            $dependent = (string) $dependent;

            // Already unconditionally required: required_with adds nothing.
            if (in_array($dependent, $required, true)) {
                continue;
            }

            // Declared by the schema but not by THIS class (dropped by the
            // read/write split, or the discriminator owned by the morph base):
            // conditionally requiring a field the class cannot carry would
            // reject every trigger-bearing payload.
            if (array_key_exists($dependent, $properties) && ! array_key_exists($dependent, $declaredNullable)) {
                continue;
            }

            $usable = [];
            foreach ($this->dedupe($triggers) as $trigger) {
                // A self-dependency is a tautology in JSON Schema (a present
                // field is present); required_with on the field itself would
                // instead reject present-but-empty values, so it is dropped.
                if ($trigger === $dependent) {
                    continue;
                }

                // Laravel rule-string parameters are comma-separated, so a
                // trigger name containing a comma cannot be expressed; skip it
                // loudly instead of emitting a rule that watches wrong fields.
                if (str_contains($trigger, ',')) {
                    $this->warnings[sprintf(
                        'Schema "%s": dependentRequired trigger "%s" contains a comma, which cannot be expressed in a Laravel required_with parameter list; the dependency of "%s" on it is not enforced.',
                        $base,
                        $trigger,
                        $dependent,
                    )] = true;

                    continue;
                }

                $usable[] = $trigger;
            }

            if ($usable === []) {
                continue;
            }

            $name = ($declaredNullable[$dependent] ?? false) ? 'present_with:' : 'required_with:';
            $expression = "'".$this->escapeSingleQuoted($name.implode(',', $usable))."'";

            $existing = $rules[$dependent] ?? [];
            if (($existing[0] ?? null) === "'sometimes'") {
                $existing[0] = $expression;
            } else {
                array_unshift($existing, $expression);
            }
            $rules[$dependent] = $existing;
        }
    }

    /**
     * The `dependentRequired` map of a schema with any `allOf` members merged
     * in. allOf composition ANDs every member, so the merge is a union: the
     * same trigger from several sources unions its dependent lists. Keys are
     * trigger property names, values the properties the trigger requires, in
     * spec order (own entries first, then members in source order).
     *
     * @param  array<string, true>  $seen  component names already visited (keyed for O(1) cycle checks)
     * @return array<string, list<string>>
     */
    private function mergedDependentRequired(Schema $schema, array $seen = []): array
    {
        $map = $this->localDependentRequired($schema);

        $members = $schema->allOf;
        if (! is_array($members)) {
            return $map;
        }

        foreach ($members as $member) {
            $resolved = $this->resolveMemberSchema($member, $seen);
            if ($resolved === null) {
                continue;
            }

            [$memberSchema, $memberSeen] = $resolved;
            foreach ($this->mergedDependentRequired($memberSchema, $memberSeen) as $trigger => $dependents) {
                $map[$trigger] = $this->dedupe(array_merge($map[$trigger] ?? [], $dependents));
            }
        }

        return $map;
    }

    /**
     * The schema's own `dependentRequired` map. cebe has no typed attribute for
     * `dependentRequired` (a JSON Schema keyword OpenAPI 3.1 admits), so it is
     * read from the serialized data, where cebe keeps unknown keys verbatim.
     * The spec is untrusted input: only the well-formed shape (a non-empty
     * trigger name mapping to an array of non-empty string names) is accepted,
     * anything else is ignored.
     *
     * @return array<string, list<string>>
     */
    private function localDependentRequired(Schema $schema): array
    {
        $serialized = (array) $schema->getSerializableData();
        $raw = $serialized['dependentRequired'] ?? null;
        if (is_object($raw)) {
            $raw = (array) $raw;
        }
        if (! is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $trigger => $dependents) {
            $trigger = (string) $trigger;
            if ($trigger === '' || ! is_array($dependents)) {
                continue;
            }

            $names = [];
            foreach ($dependents as $dependent) {
                if (is_string($dependent) && $dependent !== '') {
                    $names[] = $dependent;
                }
            }
            if ($names !== []) {
                $map[$trigger] = $this->dedupe($names);
            }
        }

        return $map;
    }

    /**
     * @return array{0: ?list<string>, 1: bool}
     */
    private function itemRules(Schema $schema): array
    {
        $items = $schema->items;

        if ($items instanceof Reference) {
            $enumClass = $this->referencedEnumClass($items);

            return $enumClass !== null ? [['Rule::enum('.$enumClass.'::class)'], true] : [null, false];
        }

        if (! $items instanceof Schema) {
            return [null, false];
        }

        if ($this->notEmptyArray($items->enum)) {
            $values = $this->enumValues($items);
            if ($values !== []) {
                return [['Rule::in(['.implode(', ', array_map(fn (string|int|float|bool $value): string => $this->scalarLiteral($value), $values)).'])'], true];
            }
        }

        $primary = $this->normalizeTypes($items)[0] ?? null;

        $rules = match ($primary) {
            'string' => $this->stringRules($items),
            'integer' => array_merge(["'integer'"], $this->numericRules($items)),
            'number' => array_merge(["'numeric'"], $this->numericRules($items)),
            'boolean' => ["'boolean'"],
            default => [],
        };

        return [$rules === [] ? null : $rules, false];
    }

    /**
     * Wildcard value rules for a pure-map property (`field.*`). The rules are
     * derived from the map's `additionalProperties` value schema, reusing the
     * same scalar-constraint logic as array items so a value schema of
     * `{type: string, maxLength: 10}` yields `['string', 'max:10']`.
     *
     * A `$ref` value (map of objects) yields `['array']`: laravel-data will not
     * auto-hydrate map values into Data instances (only DataCollection elements
     * and typed params auto-cast), so the values arrive as raw arrays and we
     * only assert that shape. An untyped map (`additionalProperties: true`)
     * yields no value rule.
     *
     * @return array{0: ?list<string>, 1: bool} value rules, whether Rule:: is used
     */
    private function mapValueRules(Schema $schema): array
    {
        $value = $this->additionalPropertiesSchema($schema);

        if ($value === true || $value === null) {
            return [null, false];
        }

        if ($value instanceof Reference) {
            $enumClass = $this->referencedEnumClass($value);
            if ($enumClass !== null) {
                return [['Rule::enum('.$enumClass.'::class)'], true];
            }

            // A $ref to another component: values arrive as raw arrays.
            return [["'array'"], false];
        }

        return $this->inlineValueRules($value);
    }

    /**
     * Scalar/shape rules for one inline schema sitting in a nested value
     * position (a map value or a tuple position): the enum literals, the
     * scalar-constraint families (string/integer/number/boolean), and the
     * shape-plus-count assertion for arrays and objects. Shared by
     * mapValueRules() and prefixItemRules() so both nested contexts reuse the
     * exact same per-constraint mapping.
     *
     * @return array{0: ?list<string>, 1: bool} value rules, whether Rule:: is used
     */
    private function inlineValueRules(Schema $value): array
    {
        if ($this->notEmptyArray($value->enum)) {
            $values = $this->enumValues($value);
            if ($values !== []) {
                return [['Rule::in(['.implode(', ', array_map(fn (string|int|float|bool $v): string => $this->scalarLiteral($v), $values)).'])'], true];
            }
        }

        $primary = $this->normalizeTypes($value)[0] ?? null;

        $rules = match ($primary) {
            'string' => $this->stringRules($value),
            'integer' => array_merge(["'integer'"], $this->numericRules($value)),
            'number' => array_merge(["'numeric'"], $this->numericRules($value)),
            'boolean' => ["'boolean'"],
            'array' => array_merge(["'array'"], $this->arrayCountRules($value)),
            // An object map value carries its own key-count bounds (issue #72).
            'object' => array_merge(["'array'"], $this->objectCountRules($value)),
            default => [],
        };

        return [$rules === [] ? null : $rules, false];
    }

    /**
     * The tuple position schemas a 3.1 `prefixItems` declares, keyed by their
     * zero-based position. cebe has no typed attribute for `prefixItems` (a
     * JSON Schema keyword OpenAPI 3.1 admits), so it is read from the
     * serialized data, where cebe keeps unknown keys verbatim, and each entry
     * is instantiated into the cebe object model here. The spec is untrusted
     * input: a malformed entry (a non-object, or data cebe rejects) maps to
     * null so the later positions keep their correct index, and a `prefixItems`
     * that is not a non-empty list at all yields []. A non-empty result, even
     * one of all nulls, signals that the schema IS a tuple, which suppresses
     * the post-prefix `items` wildcard rules (they would false-reject valid
     * prefix positions).
     *
     * @return array<int, Schema|Reference|null>
     */
    private function prefixItemSchemas(Schema $schema): array
    {
        $serialized = (array) $schema->getSerializableData();
        $raw = $serialized['prefixItems'] ?? null;
        if (! is_array($raw) || $raw === [] || ! array_is_list($raw)) {
            return [];
        }

        $positions = [];
        foreach ($raw as $index => $entry) {
            if (! is_array($entry)) {
                $positions[$index] = null;

                continue;
            }

            if (isset($entry['$ref']) && is_string($entry['$ref'])) {
                $positions[$index] = new Reference(['$ref' => $entry['$ref']], null);

                continue;
            }

            try {
                $positions[$index] = new Schema($entry);
            } catch (TypeErrorException) {
                $positions[$index] = null;
            }
        }

        return $positions;
    }

    /**
     * Per-index rules for a tuple's `prefixItems` positions (issue #82), keyed
     * by their suffix relative to the property name ('.0', '.1', ...). Each
     * position reuses the shared inline-value mapping (scalar constraints,
     * enums, formats); a nullable position is prefixed with `nullable` so a
     * spec-valid null is not rejected. Mirroring buildRules(), a multi-type
     * position and a composition keyword stay presence-only (no rule), since a
     * single type rule would false-reject the other valid members. A `$ref`
     * position resolves like a property-level $ref: a backed enum gets its
     * Rule::enum, a scalar/array alias is followed to its terminal schema, and
     * an object component is asserted as an array shape.
     *
     * @param  array<int, Schema|Reference|null>  $positions
     * @return array{0: array<string, list<string>>, 1: bool} rules keyed by index suffix, whether Rule:: is used
     */
    private function prefixItemRules(array $positions): array
    {
        $map = [];
        $uses = false;

        foreach ($positions as $index => $position) {
            if ($position === null) {
                continue;
            }

            [$rules, $positionUses] = $this->prefixItemValueRules($position);
            if ($rules !== null && $rules !== []) {
                $map['.'.$index] = $rules;
                $uses = $uses || $positionUses;
            }
        }

        return [$map, $uses];
    }

    /**
     * @return array{0: ?list<string>, 1: bool}
     */
    private function prefixItemValueRules(Schema|Reference $position): array
    {
        if ($position instanceof Reference) {
            $enumClass = $this->referencedEnumClass($position);
            if ($enumClass !== null) {
                return [['Rule::enum('.$enumClass.'::class)'], true];
            }

            // A scalar/array/union alias position enforces its terminal
            // schema's constraints, exactly like an alias at a property site.
            $aliasSchema = $this->referencedAliasSchema($position);
            if ($aliasSchema !== null) {
                return $this->prefixItemValueRules($this->terminalAliasSchema($aliasSchema));
            }

            // A map or object component arrives as a raw array: assert the
            // shape only (nested hydration is a typing concern, not a rules
            // one). An unresolvable $ref stays presence-only.
            if ($this->referencedMapSchema($position) !== null || $this->referencedObjectSchema($position) !== null) {
                return [["'array'"], false];
            }

            return [null, false];
        }

        // A multi-type position (`type: ["string", "integer"]`) and a
        // composition keyword stay presence-only: a single type rule would
        // wrongly reject the other valid members (mirrors buildRules()).
        if (count($this->normalizeTypes($position)) > 1
            || $this->notEmptyArray($position->oneOf)
            || $this->notEmptyArray($position->anyOf)
            || $this->notEmptyArray($position->allOf)) {
            return [null, false];
        }

        [$rules, $uses] = $this->inlineValueRules($position);

        if ($rules !== null && $this->isNullable($position)) {
            $rules = array_merge(["'nullable'"], $rules);
        }

        return [$rules, $uses];
    }

    /**
     * If a reference points at a pure-map component, return that component's
     * schema so the caller can derive the map value rules. Returns null when the
     * reference is not a pure-map component.
     */
    private function referencedMapSchema(Reference $reference): ?Schema
    {
        $name = $this->refName($reference->getReference());

        return $name !== null ? ($this->mapSchemas[$name] ?? null) : null;
    }

    /**
     * If a reference points at an emitted object Data-class component, return
     * that component's schema so the caller can read schema-level constraints
     * (minProperties/maxProperties, issue #72) that must be enforced at the use
     * site. Returns null for enums and unregistered names.
     */
    private function referencedObjectSchema(Reference $reference): ?Schema
    {
        $name = $this->refName($reference->getReference());
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
    private function referencedAliasSchema(Reference $reference): ?Schema
    {
        $name = $this->refName($reference->getReference());

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
    private function terminalAliasSchema(Schema $schema, array $seen = []): Schema
    {
        $ref = $this->bareAllOfRef($schema);
        if ($ref === null) {
            return $schema;
        }

        $name = $this->refName($ref->getReference());
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
     * @return list<string>
     */
    private function stringRules(Schema $schema): array
    {
        $rules = ["'string'"];

        $max = $schema->maxLength;
        if (is_int($max)) {
            $rules[] = "'max:".$max."'";
        }

        $min = $schema->minLength;
        if (is_int($min)) {
            $rules[] = "'min:".$min."'";
        }

        $format = $schema->format;
        if (is_string($format)) {
            $formatRule = $this->formatRule($format);
            if ($formatRule !== null) {
                $rules[] = $formatRule;
            }
        }

        $pattern = $schema->pattern;
        if (is_string($pattern)) {
            $regexRule = $this->regexRule($pattern);
            if ($regexRule !== null) {
                $rules[] = $regexRule;
            }
        }

        return $rules;
    }

    /**
     * Numeric constraints: minimum/maximum (inclusive -> min:/max:), the
     * exclusive forms (strictly greater/less -> gt:/lt:), and multipleOf.
     *
     * Exclusive bounds come in two spec flavours. OpenAPI 3.0 uses a boolean
     * companion: `minimum: N` plus `exclusiveMinimum: true` means strictly
     * greater, so emit `gt:N` instead of `min:N`. OpenAPI 3.1 uses a numeric
     * keyword: `exclusiveMinimum: N` (a number) means strictly greater than N,
     * so emit `gt:N` on its own. cebe defaults the boolean companion to `false`
     * when `minimum` is present, so we read getSerializableData() to tell an
     * explicit `exclusiveMinimum: false` (inclusive) apart from the default.
     *
     * @return list<string>
     */
    private function numericRules(Schema $schema): array
    {
        $rules = [];

        $serialized = (array) $schema->getSerializableData();
        $exclusiveMin = $serialized['exclusiveMinimum'] ?? null;
        $exclusiveMax = $serialized['exclusiveMaximum'] ?? null;

        // 3.1 numeric exclusiveMinimum: a strict lower bound on its own.
        if (is_int($exclusiveMin) || is_float($exclusiveMin)) {
            $rules[] = "'gt:".$this->numberLiteral($exclusiveMin)."'";
        }
        if (is_int($exclusiveMax) || is_float($exclusiveMax)) {
            $rules[] = "'lt:".$this->numberLiteral($exclusiveMax)."'";
        }

        $min = $schema->minimum;
        if (is_int($min) || is_float($min)) {
            // 3.0 boolean exclusiveMinimum: true upgrades the bound to strict.
            $rules[] = $exclusiveMin === true
                ? "'gt:".$this->numberLiteral($min)."'"
                : "'min:".$this->numberLiteral($min)."'";
        }

        $max = $schema->maximum;
        if (is_int($max) || is_float($max)) {
            $rules[] = $exclusiveMax === true
                ? "'lt:".$this->numberLiteral($max)."'"
                : "'max:".$this->numberLiteral($max)."'";
        }

        $multipleOf = $schema->multipleOf;
        if ((is_int($multipleOf) || is_float($multipleOf)) && $multipleOf > 0) {
            $rules[] = 'new MultipleOfRule('.$this->numberLiteral($multipleOf).')';
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function arrayCountRules(Schema $schema): array
    {
        $rules = [];

        $max = $schema->maxItems;
        if (is_int($max)) {
            $rules[] = "'max:".$max."'";
        }

        $min = $schema->minItems;
        if (is_int($min)) {
            $rules[] = "'min:".$min."'";
        }

        return $rules;
    }

    /**
     * minProperties/maxProperties as Laravel key-count rules (issue #72). A
     * JSON object arrives as a PHP array, and Laravel's `min:`/`max:` count the
     * elements of an array value, so the property count maps directly onto
     * `min:`/`max:`, exactly like minItems/maxItems on an array.
     *
     * Callers must only emit these alongside an `'array'` rule on a schema
     * KNOWN to describe an object (a typed map or an explicit `type: object`).
     * On an untyped schema the instance may legally be a non-object, where
     * JSON Schema ignores minProperties/maxProperties entirely but Laravel's
     * `min:`/`max:` would measure a string's length or a number's value and
     * false-reject valid data, so untyped schemas are skipped.
     *
     * @return list<string>
     */
    private function objectCountRules(Schema $schema): array
    {
        $rules = [];

        $max = $schema->maxProperties;
        if (is_int($max)) {
            $rules[] = "'max:".$max."'";
        }

        $min = $schema->minProperties;
        if (is_int($min)) {
            $rules[] = "'min:".$min."'";
        }

        return $rules;
    }

    private function formatRule(string $format): ?string
    {
        return match ($format) {
            'email', 'idn-email' => "'email'",
            'uuid' => "'uuid'",
            // A `date` is a calendar date with no time: pin it to Y-m-d so a
            // timestamp is rejected. A `date-time` is an RFC3339 timestamp: a
            // dedicated rule accepts the Z/offset and fractional-second forms and
            // rejects a bare date, which the old shared 'date' rule wrongly let
            // through (and also accepted many non-RFC3339 strings).
            'date' => "'date_format:Y-m-d'",
            'date-time' => 'new Rfc3339DateTimeRule',
            // A `time` is an RFC3339 full-time: a dedicated rule accepts the
            // Z/offset and fractional-second forms and a bare local time, and
            // rejects an out-of-range or malformed time (date_format:H:i:s would
            // be too strict, false-rejecting offsets and fractional seconds). A
            // `duration` is an ISO 8601 duration: a dedicated rule enforces the
            // `P...T...` grammar and rejects garbage. Both previously fell through
            // to no rule, so any string was silently accepted.
            'time' => 'new Rfc3339TimeRule',
            'duration' => 'new Iso8601DurationRule',
            'uri', 'url', 'iri' => "'url'",
            'ipv4' => "'ipv4'",
            'ipv6' => "'ipv6'",
            'ip' => "'ip'",
            // An RFC1123 hostname gets a real rule that enforces dot-separated
            // letter/digit/hyphen labels with no leading/trailing hyphen. The
            // internationalized `idn-hostname` keeps a softer non-whitespace
            // check: a strict ASCII regex would wrongly reject valid unicode
            // labels, and full unicode/punycode validation is out of scope.
            'hostname' => 'new HostnameRule',
            'idn-hostname' => "'regex:/^\\S+\$/'",
            // Both formats already carry the leading 'string' from stringRules,
            // so neither re-adds a redundant 'string' here.
            default => null,
        };
    }

    /**
     * Candidate PCRE delimiters, tried in order. The first one not present in the
     * pattern is used so the pattern never needs internal escaping. None of these
     * are alphanumeric, backslash, or whitespace, all of which PCRE forbids as
     * delimiters.
     *
     * @var list<string>
     */
    private const REGEX_DELIMITERS = ['#', '~', '/', '!', '@', '%', '|', ';', ',', '=', '+'];

    private function regexRule(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }

        return "'regex:".$this->escapeSingleQuoted($this->delimitedPattern($pattern))."'";
    }

    /**
     * Wrap a spec-derived pattern in PCRE delimiters: the first candidate not
     * present in the pattern, so the pattern never needs internal escaping. When
     * every candidate appears, fall back to a fixed delimiter and
     * backslash-escape its unescaped occurrences so the resulting PCRE stays
     * valid. Spec patterns are ECMA-262; consistent with the `pattern` rule,
     * they are embedded as PCRE without dialect translation.
     */
    private function delimitedPattern(string $pattern): string
    {
        foreach (self::REGEX_DELIMITERS as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                return $candidate.$pattern.$candidate;
            }
        }

        $delimiter = self::REGEX_DELIMITERS[0];

        return $delimiter.$this->escapeDelimiter($pattern, $delimiter).$delimiter;
    }

    /**
     * Backslash-escape every unescaped occurrence of $delimiter in $pattern, so
     * the pattern can be wrapped in that delimiter without prematurely closing
     * it. An occurrence already preceded by an odd number of backslashes is
     * already escaped and left untouched.
     */
    private function escapeDelimiter(string $pattern, string $delimiter): string
    {
        $result = '';
        $backslashes = 0;

        foreach (str_split($pattern) as $char) {
            if ($char === $delimiter && $backslashes % 2 === 0) {
                $result .= '\\';
            }
            $result .= $char;
            $backslashes = $char === '\\' ? $backslashes + 1 : 0;
        }

        return $result;
    }

    private function referencedEnumClass(Reference $reference): ?string
    {
        $name = $this->refName($reference->getReference());

        if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'enum') {
            // Every Rule::enum(X::class) expression flows through here, so
            // recording the reference at this single chokepoint lets the
            // grouped layout (issue #93) import an enum a rule references even
            // when the property type itself does not mention it.
            $this->noteClassRef($this->registry[$name]['class']);

            return $this->registry[$name]['class'];
        }

        return null;
    }

    private function scalarLiteral(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return $this->numberLiteral($value);
        }

        return "'".$this->escapeSingleQuoted($value)."'";
    }

    private function numberLiteral(int|float $value): string
    {
        return (string) $value;
    }

    /**
     * The validation extension trait (issue #83): when output.validation_trait
     * names a user-owned trait, every generated Data class carries a
     * `use <Trait>;` body line so laravel-data's method_exists() discovery
     * finds the trait's static messages() / attributes() methods. The trait is
     * imported by short name (skipped when it already lives in the Data
     * namespace), the exact form Laravel Pint's `fully_qualified_strict_types`
     * fixer produces, so the output stays formatter-idempotent. A short name
     * that collides with the class itself or a differently-rooted import would
     * emit a PHP fatal, so it fails loudly instead of writing a broken file.
     *
     * @param  list<string>  $imports  the class's imports, already collected
     * @return array{0: list<string>, 1: string} imports (trait FQCN merged in, re-sorted) and the indented trait-use body line, '' when no trait is configured
     */
    private function applyValidationTrait(string $className, array $imports): array
    {
        $trait = $this->options->validationTrait;
        if ($trait === null) {
            return [$imports, ''];
        }

        $fqcn = ltrim($trait, '\\');
        $separator = strrpos($fqcn, '\\');
        $shortName = $separator === false ? $fqcn : substr($fqcn, $separator + 1);
        $traitNamespace = $separator === false ? '' : substr($fqcn, 0, $separator);

        if ($shortName === $className) {
            throw new GenerationException(
                "output.validation_trait '{$trait}' has the same short name as the generated class {$className}; rename the trait or the colliding schema."
            );
        }

        foreach ($imports as $import) {
            $importShort = ($pos = strrpos($import, '\\')) === false ? $import : substr($import, $pos + 1);
            if ($importShort === $shortName && $import !== $fqcn) {
                throw new GenerationException(
                    "output.validation_trait '{$trait}' short name collides with the import {$import} in {$className}; use a trait whose short name does not clash."
                );
            }
        }

        // Compared against the class's EFFECTIVE namespace: under the grouped
        // layout (issue #93) a class in a tag subnamespace must import a trait
        // living at the flat Data root, and vice versa.
        if ($traitNamespace !== ltrim($this->namespaceFor($className), '\\') && ! in_array($fqcn, $imports, true)) {
            $imports[] = $fqcn;
            sort($imports);
        }

        return [$imports, '    use '.$shortName.';'];
    }

    /**
     * @param  list<array{code: string, imports: list<string>}>  $params
     * @param  list<string>  $imports
     * @param  array<string, list<string>>  $rules
     * @param  list<string>  $classDoc  class-level docblock lines, in emit order; empty means no docblock
     * @param  list<string>|null  $fromQueryBooleans  non-null emits the query-only fromQuery() factory (per-operation query classes, issue #63); the list holds the wire names of boolean parameters that need true/false literal mapping
     */
    private function renderDataClass(string $className, array $params, array $imports, array $rules, array $classDoc = [], ?array $fromQueryBooleans = null): string
    {
        [$imports, $traitUse] = $this->applyValidationTrait($className, $imports);

        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        // One doc line renders the established single-`*`-line block (so existing
        // output stays byte-identical); multiple lines render one ` * ` per line.
        $docBlock = $classDoc === []
            ? ''
            : "/**\n".implode("\n", array_map(static fn (string $line): string => ' * '.$line, $classDoc))."\n */\n";

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->namespaceFor($className).";\n\n".$useBlock."\n\n".$docBlock.'final class '.$className.' extends Data';

        if ($params === []) {
            // An object with no constructor properties is normally an empty class.
            // The one exception is a closed object with no named properties
            // (additionalProperties: false, empty properties): it carries the
            // closed-object rule but no params, so emit just the rules() method.
            if ($rules === []) {
                // An empty body carries a marker comment (issue #95) so the gap
                // is visible in the generated code: without it the class would
                // compile fine while silently dropping every payload field. The
                // comment makes the body non-empty, so Pint's
                // `single_line_empty_body` fixer leaves it alone and the output
                // stays formatter-idempotent.
                if ($traitUse !== '') {
                    return $header."\n{\n".$traitUse."\n\n    ".self::EMPTY_BODY_MARKER."\n}\n";
                }

                return $header."\n{\n    ".self::EMPTY_BODY_MARKER."\n}\n";
            }

            // With the validation trait the rules() method follows the trait-use
            // line, separated by renderRules()'s own leading blank line.
            if ($traitUse !== '') {
                return $header."\n{\n".$traitUse.$this->renderRules($rules)."\n}\n";
            }

            // renderRules() prefixes a blank line so it separates cleanly from a
            // preceding constructor. With no constructor the method sits right
            // under the opening brace, so collapse that leading blank line to a
            // single newline: Pint's class_attributes_separation fixer forbids a
            // blank line immediately after `{`, and emitting one would make the
            // output non-idempotent.
            $rulesBody = preg_replace('/^\n\n/', "\n", $this->renderRules($rules));

            return $header."\n{".$rulesBody."\n}\n";
        }

        $body = implode("\n", array_map(static fn (array $p): string => $p['code'], $params));
        $constructor = "    public function __construct(\n".$body."\n    ) {}";

        $factory = $fromQueryBooleans !== null ? "\n\n".$this->renderFromQuery($fromQueryBooleans) : '';

        $traitBlock = $traitUse !== '' ? $traitUse."\n\n" : '';

        return $header."\n{\n".$traitBlock.$constructor.$factory.$this->renderRules($rules)."\n}\n";
    }

    /**
     * The query-only creation factory every per-operation query Data class
     * carries (issue #63). It validates against rules() and hydrates from the
     * request's query string ONLY, so request-body fields never bleed into
     * query validation (and vice versa). Because the method name starts with
     * `from` and accepts a Request, laravel-data also picks it up as the magic
     * creation method when the class is resolved from the container, so a
     * typed controller parameter hydrates through this same query-only path.
     *
     * Boolean parameters need one extra step: the OpenAPI form style
     * serializes a boolean as ?flag=true / ?flag=false, but Laravel's
     * `boolean` rule rejects those literals and PHP's coercive bool cast
     * would turn the string "false" into TRUE. The factory maps the literals
     * to '1'/'0' first, so spec-valid requests validate and hydrate
     * correctly.
     *
     * @param  list<string>  $booleanNames  wire names of the class's boolean parameters, in spec order
     */
    private function renderFromQuery(array $booleanNames): string
    {
        $doc = '    /**'."\n"
            .'     * Validate against rules() and hydrate from the query string only, so'."\n"
            .'     * request-body fields never bleed into query validation (or vice versa).'."\n";

        if ($booleanNames === []) {
            return $doc
                .'     */'."\n"
                .'    public static function fromQuery(Request $request): static'."\n"
                ."    {\n"
                .'        return self::validateAndCreate($request->query->all());'."\n"
                .'    }';
        }

        $names = implode(', ', array_map(
            fn (string $name): string => "'".$this->escapeSingleQuoted($name)."'",
            $booleanNames,
        ));

        return $doc
            .'     * Boolean parameters arrive as the form-style literals true / false,'."\n"
            .'     * which are mapped to 1 / 0 before validation.'."\n"
            .'     */'."\n"
            .'    public static function fromQuery(Request $request): static'."\n"
            ."    {\n"
            .'        $query = $request->query->all();'."\n"
            ."\n"
            .'        foreach (['.$names.'] as $name) {'."\n"
            .'            if (array_key_exists($name, $query)) {'."\n"
            .'                $query[$name] = match ($query[$name]) {'."\n"
            ."                    'true' => '1',"."\n"
            ."                    'false' => '0',"."\n"
            .'                    default => $query[$name],'."\n"
            .'                };'."\n"
            .'            }'."\n"
            .'        }'."\n"
            ."\n"
            .'        return self::validateAndCreate($query);'."\n"
            .'    }';
    }

    /**
     * Render the abstract base class of a discriminated union: only the
     * discriminator property (marked `#[PropertyForMorph]` plus its validation
     * attributes) and a morph() that maps each discriminator value to a variant.
     *
     * @param  list<string>  $imports
     * @param  list<string>  $validationAttributes  short attribute names, e.g. ['Required', 'StringType']
     * @param  list<string>  $arms  match() arm lines, already indented
     * @param  ?string  $deprecationTag  the class-level `@deprecated ...` line, or null
     */
    private function renderDiscriminatorBase(string $className, array $imports, string $mapName, string $propertyName, ResolvedType $type, array $validationAttributes, array $arms, ?string $deprecationTag = null): string
    {
        [$imports, $traitUse] = $this->applyValidationTrait($className, $imports);

        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        $docBlock = $deprecationTag !== null ? '/**'."\n".' * '.$deprecationTag."\n".' */'."\n" : '';

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->namespaceFor($className).";\n\n".$useBlock."\n\n".$docBlock
            .'abstract class '.$className.' extends Data implements PropertyMorphableData';

        $attributeLine = '        #[PropertyForMorph, '.implode(', ', $validationAttributes)."]\n";

        $constructor = "    public function __construct(\n"
            .$mapName
            .$attributeLine
            .'        public readonly '.$type->declaration().' $'.$propertyName.",\n"
            .'    ) {}';

        // spatie's DataMorphClassResolver builds the morph() input keyed by the
        // PHP PROPERTY name (DataProperty->name), not the wire name, even when a
        // #[MapName] remaps the input. So match on the property name: a
        // discriminator that needs a MapName (pet_type -> petType) would otherwise
        // read a missing key and always morph to null, rejecting every payload.
        $morph = "\n\n    /**\n     * @param  array<string, mixed>  \$properties\n     */\n    public static function morph(array \$properties): ?string\n    {\n"
            .'        return match ($properties['."'".$this->escapeSingleQuoted($propertyName)."'".'] ?? null) {'."\n"
            .implode("\n", $arms)."\n"
            ."        };\n    }";

        $traitBlock = $traitUse !== '' ? $traitUse."\n\n" : '';

        return $header."\n{\n".$traitBlock.$constructor.$morph."\n}\n";
    }

    /**
     * Render a variant class of a discriminated union: it extends the base,
     * forwards the discriminator (a non-promoted param) to the parent, and
     * declares its own promoted readonly properties plus a rules() method.
     *
     * @param  list<array{code: string, imports: list<string>}>  $params
     * @param  list<string>  $imports
     * @param  array<string, list<string>>  $rules
     * @param  ?string  $deprecationTag  the class-level `@deprecated ...` line, or null
     */
    private function renderVariantClass(string $className, string $baseClass, string $discriminatorProperty, string $discriminatorDeclaration, array $params, array $imports, array $rules, ?string $deprecationTag = null): string
    {
        [$imports, $traitUse] = $this->applyValidationTrait($className, $imports);

        // The base lives in the same namespace, so a variant can have zero
        // imports; emit no `use` block then (avoid stray blank lines).
        $useBlock = $imports === []
            ? ''
            : implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports))."\n\n";

        $docBlock = $deprecationTag !== null ? '/**'."\n".' * '.$deprecationTag."\n".' */'."\n" : '';

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->namespaceFor($className).";\n\n".$useBlock.$docBlock
            .'final class '.$className.' extends '.$baseClass;

        // The discriminator is a forwarded, non-promoted parameter so only the
        // base declares it as a property (PHP forbids redeclaring a parent's
        // promoted property). It comes first, then the variant's own properties.
        // Its type matches the base property exactly (string or int).
        $forwarded = '        '.$discriminatorDeclaration.' $'.$discriminatorProperty.','.($params !== [] ? "\n" : '');
        $body = implode("\n", array_map(static fn (array $p): string => $p['code'], $params));

        $constructor = "    public function __construct(\n"
            .$forwarded.$body."\n"
            ."    ) {\n        parent::__construct(\$".$discriminatorProperty.");\n    }";

        $traitBlock = $traitUse !== '' ? $traitUse."\n\n" : '';

        return $header."\n{\n".$traitBlock.$constructor.$this->renderRules($rules)."\n}\n";
    }

    /**
     * @param  array<string, list<string>>  $rules
     */
    private function renderRules(array $rules): string
    {
        if ($rules === []) {
            return '';
        }

        $lines = [];
        foreach ($rules as $key => $expressions) {
            $lines[] = "            '".$this->escapeSingleQuoted((string) $key)."' => [".implode(', ', $expressions).'],';
        }

        // The key type is `array-key`, not `string`: a property whose JSON name is
        // a numeric string (e.g. GitHub reaction counts keyed `+1`, `-1`) becomes
        // an int array key in PHP, so PHPStan infers an `int|string`-keyed array
        // and a `string`-keyed return type would not match it. `array-key` covers
        // both without widening the value type.
        return "\n\n    /**\n     * @return array<array-key, list<string|object>>\n     */\n    public static function rules(): array\n    {\n        return [\n".implode("\n", $lines)."\n        ];\n    }";
    }

    private function resolveType(Schema|Reference $schema, string $nameHint, int $depth, string $variant = 'all'): ResolvedType
    {
        if ($depth > $this->options->maxDepth) {
            throw new GenerationException("Maximum schema depth ({$this->options->maxDepth}) exceeded at {$nameHint}.");
        }

        if ($schema instanceof Reference) {
            return $this->resolveReference($schema);
        }

        // oneOf/anyOf become a native PHP union type when every member resolves
        // to a clean type (scalar or generated Data class); otherwise they fall
        // back to mixed. allOf is merged separately below into a nested object.
        // oneOf and anyOf are treated identically for typing: both express "the
        // value is one of these member shapes" and PHP cannot distinguish them.
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return $this->resolveUnion($schema, $nameHint, $depth, $variant);
        }

        $types = $this->normalizeTypes($schema);
        $nullable = $this->isNullable($schema);
        $primary = $types[0] ?? null;

        // A multi-type schema (`type: ["string", "integer"]`, the JSON Schema
        // type array with more than one non-null member) is a union, not just its
        // first type. Emit a native PHP union (string|int) so a valid integer is
        // not rejected. A single non-null member plus `null` is a nullable scalar
        // and is handled by the single-scalar path below (normalizeTypes already
        // dropped the `null`).
        if (count($types) > 1) {
            $union = $this->resolveScalarTypeUnion($types, $nullable);
            if ($union !== null) {
                return $union;
            }
        }

        if ($primary !== null && isset(self::SCALARS[$primary])) {
            return new ResolvedType(self::SCALARS[$primary], $nullable);
        }

        // A bare `const` with no declared type: infer the PHP type from the
        // const literal so the property is typed (string or int) rather than
        // mixed. A typed const already took the scalar path above.
        if ($primary === null) {
            $const = $this->constValue($schema);
            if ($const !== null) {
                return new ResolvedType(is_int($const[0]) ? 'int' : 'string', $nullable);
            }
        }

        if ($primary === 'array') {
            return $this->resolveArray($schema, $nameHint, $depth, $nullable, $variant);
        }

        // A pure-map inline schema (only additionalProperties, no named
        // properties) becomes a typed array<string, X> rather than a nested
        // Data class.
        if ($this->isPureMap($schema)) {
            return $this->mapType($schema, $nameHint, $depth, $variant);
        }

        // A merged schema is nullable if the composing schema OR any allOf
        // member is nullable (3.0 `nullable: true` or 3.1 `type: [..., null]`).
        $allOfNullable = $this->notEmptyArray($schema->allOf)
            ? $this->mergedNullable($schema)
            : $nullable;

        // The common "single $ref wrapped in allOf" pattern (a ref plus a
        // description) is just an alias: resolve it to the referenced type
        // instead of inlining a fresh nested copy. This also breaks self
        // recursion (e.g. a `templateEvent: allOf: [$ref Self]` property). The
        // bare-ref check accepts a $ref to a non-object alias too, so a chained
        // alias (allOf-ref -> scalar/array/union) resolves through to the
        // underlying type via resolveReference rather than an empty class.
        $aliasRef = $this->bareAllOfRef($schema);
        if ($aliasRef !== null) {
            $resolved = $this->resolveReference($aliasRef);

            return $allOfNullable ? new ResolvedType($resolved->declaration, true, $resolved->docType, $resolved->imports, $resolved->dataCollectionOf, $resolved->isUnion, $resolved->isMap, $resolved->isEnum, $resolved->classRefs) : $resolved;
        }

        // An allOf member set merges to an object even when `type: object` is
        // omitted, so treat a present allOf as an object shape too.
        if ($primary === 'object'
            || ($primary === null && $this->notEmptyArray($schema->properties))
            || ($primary === null && $this->notEmptyArray($schema->allOf))
        ) {
            return $this->resolveInlineObject($schema, $nameHint, $depth, $allOfNullable, $variant);
        }

        return new ResolvedType('mixed', $nullable);
    }

    /**
     * Resolve a schema whose type is expressed via `oneOf`/`anyOf` to a native
     * PHP union type when every member resolves cleanly, else to `mixed`.
     *
     * A member resolves cleanly when it is a scalar (string/int/float/bool) or a
     * generated Data class. Members are taken in source order, deduplicated by
     * their PHP type, and the union is rendered in that stable order with `null`
     * forced to the end. The union is nullable (and the property defaults to
     * null) when any member is the `null` type, any member is itself nullable,
     * or the composing schema is nullable.
     *
     * Anything messier (a member that is itself an oneOf/anyOf, an array, a map,
     * an untyped/empty schema, or an unresolvable $ref) makes the whole union
     * fall back to `mixed` with presence-only rules. This keeps the established
     * fallback behavior and is expected for the genuinely ambiguous cases. The
     * fallback is deterministic: the same spec always lands on the same result.
     */
    private function resolveUnion(Schema $schema, string $nameHint, int $depth, string $variant): ResolvedType
    {
        $members = $this->unionMembers($schema);

        // A `oneOf`/`anyOf` with no usable members carries no shape: mixed.
        if ($members === []) {
            return new ResolvedType('mixed', $this->isNullable($schema));
        }

        // Pre-scan every member for the `null` type up front, not incrementally,
        // so a union whose `type: null` member sits AFTER a messy member still
        // resolves as nullable. Without this, an early messy-member fallback (a
        // `type: object` member before the `null` member) returned a non-nullable
        // `mixed`, and a required+nullable oneOf then emitted a bare `required`
        // rule that false-rejected a spec-valid present null (issue #8).
        $nullable = $this->isNullable($schema);
        foreach ($members as $member) {
            if ($this->isNullTypeMember($member)) {
                $nullable = true;

                break;
            }
        }

        $declarations = [];
        $imports = [];
        $classRefs = [];
        $hasObjectMember = false;

        foreach ($members as $member) {
            // A `{type: null}` member contributes nullability, not a type.
            if ($this->isNullTypeMember($member)) {
                continue;
            }

            if (! $this->isCleanUnionMember($member)) {
                // Any messy member collapses the whole union to mixed. The
                // nullability pre-scanned above still informs the fallback so a
                // nullable union does not silently lose its null. The collapse
                // is surfaced as a build warning naming the schema (and the
                // pointer, when the messy member is a $ref), issue #67.
                $this->warnings[$member instanceof Reference
                    ? sprintf(
                        '%s: a oneOf/anyOf member $ref "%s" does not resolve to a plain scalar or a generated Data class; the union degrades to mixed with presence-only validation.',
                        $this->warningContext,
                        $member->getReference(),
                    )
                    : sprintf(
                        '%s: a oneOf/anyOf member is not a plain scalar or a $ref to a generated Data class; the union degrades to mixed with presence-only validation.',
                        $this->warningContext,
                    )] = true;

                return new ResolvedType('mixed', $nullable);
            }

            $resolved = $this->resolveType($member, $nameHint, $depth + 1, $variant);

            if ($resolved->nullable) {
                $nullable = true;
            }

            if ($this->isDataClass($resolved)) {
                $hasObjectMember = true;
            }

            // Dedupe by PHP type while keeping first-seen (source) order.
            if (! in_array($resolved->declaration, $declarations, true)) {
                $declarations[] = $resolved->declaration;
            }

            foreach ($resolved->imports as $import) {
                $imports[] = $import;
            }

            foreach ($resolved->classRefs as $classRef) {
                $classRefs[] = $classRef;
            }
        }

        // Every member was the null type: nothing to union over, stay mixed.
        if ($declarations === []) {
            return new ResolvedType('mixed', $nullable);
        }

        $imports = array_values(array_unique($imports));
        $classRefs = array_values(array_unique($classRefs));
        $docType = implode('|', $nullable ? array_merge($declarations, ['null']) : $declarations);

        // An object union (`CatData|DogData`) is undiscriminated: this generator
        // does not yet emit discriminator-aware validation or hydration (1.0.0
        // work). A native `CatData|DogData` property type makes spatie/laravel-data
        // infer nested rules from the union and, lacking a discriminator, validate
        // EVERY payload against the FIRST variant, so a valid non-first variant
        // (a Dog where Cat is first) is false-rejected (issue #31). False-rejecting
        // valid data is worse than under-validating, so type the property as
        // `mixed` to suppress that nested-rule inference: presence-only rules,
        // accept any object, no false-reject. The variant union is preserved in the
        // `@var` docblock for IDE/PHPStan and the member imports are kept so the
        // referenced Data classes still resolve. Scalar-only unions (`string|int`)
        // are unaffected and keep their native union type, which validates soundly.
        if ($hasObjectMember) {
            return new ResolvedType(
                'mixed',
                $nullable,
                $docType,
                $imports,
                classRefs: $classRefs,
            );
        }

        return new ResolvedType(
            implode('|', $declarations),
            $nullable,
            $docType,
            $imports,
            null,
            true,
            classRefs: $classRefs,
        );
    }

    /**
     * Build a native PHP union from a JSON Schema multi-type array
     * (`type: ["string", "integer"]`). Each member must be a known scalar; the
     * PHP types are deduplicated in source order (so `["string","integer"]`
     * becomes `string|int`). Returns null when any member is not a plain scalar
     * (for example `array` or `object`), letting the caller fall back to its
     * existing first-type handling rather than emit an unsound union.
     *
     * Mirrors the oneOf/anyOf union machinery (resolveUnion): isUnion is set so
     * the declaration renders nullability as a trailing `|null` member, and a
     * docType lists the variants for the generated docblock.
     *
     * @param  list<string>  $types  non-null type members, in source order
     * @param  bool  $nullable  whether the property is nullable
     */
    private function resolveScalarTypeUnion(array $types, bool $nullable): ?ResolvedType
    {
        $declarations = [];

        foreach ($types as $type) {
            if (! isset(self::SCALARS[$type])) {
                return null;
            }

            $declaration = self::SCALARS[$type];
            if (! in_array($declaration, $declarations, true)) {
                $declarations[] = $declaration;
            }
        }

        // A single distinct PHP type after dedupe (e.g. `["integer","number"]`
        // both map to scalars but stay distinct; only a true single type lands
        // here) is not a union: let the normal scalar path handle it.
        if (count($declarations) < 2) {
            return null;
        }

        $docType = implode('|', $nullable ? array_merge($declarations, ['null']) : $declarations);

        return new ResolvedType(
            implode('|', $declarations),
            $nullable,
            $docType,
            [],
            null,
            true,
        );
    }

    /**
     * The combined member list of a `oneOf`/`anyOf` schema, in source order.
     * Both keywords are unioned: a schema rarely uses both, but if it does the
     * members compose into one type union (oneOf members first, then anyOf).
     *
     * @return list<Schema|Reference>
     */
    private function unionMembers(Schema $schema): array
    {
        $members = [];

        foreach ([$schema->oneOf, $schema->anyOf] as $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $member) {
                if ($member instanceof Schema || $member instanceof Reference) {
                    $members[] = $member;
                }
            }
        }

        return $members;
    }

    /**
     * Whether a union member is the bare `null` type (`{type: 'null'}` or a type
     * array of only null), which contributes nullability rather than a PHP type.
     */
    private function isNullTypeMember(Schema|Reference $member): bool
    {
        if (! $member instanceof Schema) {
            return false;
        }

        $raw = $member->type;

        if ($raw === 'null') {
            return true;
        }

        return is_array($raw) && $raw !== [] && $this->normalizeTypes($member) === [];
    }

    /**
     * Whether a union member resolves to a clean PHP type: a scalar, or a $ref to
     * a generated Data class. Anything else (a nested union, an array, a map, an
     * inline object, an untyped/empty schema, an enum-only schema, or an
     * unresolvable/pure-map $ref) is rejected so the whole union falls back to
     * mixed. Keeping the accepted set small is deliberate: the union type hint is
     * only emitted when it is unambiguously correct.
     */
    private function isCleanUnionMember(Schema|Reference $member): bool
    {
        if ($member instanceof Reference) {
            $name = $this->refName($member->getReference());
            if ($name === null) {
                return false;
            }

            // A $ref to a generated Data class is clean. A pure-map alias (typed
            // array) or an unknown/enum ref is not part of an object union.
            if (isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
                return true;
            }

            // A $ref to a non-object alias is clean only when it resolves to a
            // scalar (string|int): an array/map/union/mixed alias is not a clean
            // single union member. The resolved alias type drives the decision.
            if (isset($this->aliasSchemas[$name])) {
                $resolved = $this->resolveAlias($name, $this->aliasSeen);

                return ! $resolved->isUnion
                    && ! $resolved->isMap
                    && in_array($resolved->declaration, self::SCALARS, true);
            }

            return false;
        }

        // A nested composition keyword makes the member a non-trivial shape.
        if ($this->notEmptyArray($member->oneOf)
            || $this->notEmptyArray($member->anyOf)
            || $this->notEmptyArray($member->allOf)
        ) {
            return false;
        }

        // An enum or const member is a constrained scalar but the union would
        // not enforce it; treat only a plain scalar type as clean.
        if ($this->notEmptyArray($member->enum)) {
            return false;
        }

        $types = $this->normalizeTypes($member);

        // Exactly one declared scalar type is clean. Zero types (untyped/empty),
        // multiple types, 'array', or 'object' are not.
        if (count($types) !== 1) {
            return false;
        }

        return isset(self::SCALARS[$types[0]]);
    }

    /**
     * Resolve a `$ref` to the type it generates. The two degradation paths (an
     * external or non-schema pointer, and a pointer to a component that did not
     * become a generated type) both produce `mixed` with presence-only rules,
     * so each emits a build warning naming the pointer and the schema (or
     * operation) where it was encountered (issue #67) instead of hollowing the
     * output silently.
     */
    private function resolveReference(Reference $reference): ResolvedType
    {
        $pointer = $reference->getReference();
        $name = $this->refName($pointer);

        if ($name === null) {
            $this->warnings[sprintf(
                '%s: $ref "%s" is external or not a #/components/schemas pointer and degrades to mixed with presence-only validation. Bundle external references into one document before generating.',
                $this->warningContext,
                $pointer,
            )] = true;

            return new ResolvedType('mixed');
        }

        // A reference to a pure-map component inlines the array type at the use
        // site (the component itself has no Data class). The cached type was
        // resolved during classification (outside any emission scope), so its
        // recorded class references are replayed into the current scope here.
        if (isset($this->mapAliases[$name])) {
            $this->noteClassRef(...$this->mapAliases[$name]->classRefs);

            return $this->mapAliases[$name];
        }

        // A reference to a non-object alias component (scalar/array/union)
        // resolves to its underlying type at the use site. Resolution is
        // transitive (alias -> alias) and cycle-guarded via $aliasSeen, so a
        // chain reached mid-resolution still terminates. Cached like the map
        // aliases, so its class references are replayed too.
        if (isset($this->aliasSchemas[$name])) {
            $resolved = $this->resolveAlias($name, $this->aliasSeen);
            $this->noteClassRef(...$resolved->classRefs);

            return $resolved;
        }

        if (isset($this->registry[$name]) && is_string($this->registry[$name]['class'])) {
            // A reference to a generated backed enum is a native PHP enum, not a
            // Data class: mark it so an array of enums is not given an invalid
            // #[DataCollectionOf(SomeEnum::class)] attribute (which targets
            // class-string<BaseData>, failing PHPStan). spatie hydrates the backed
            // enum from the typed array via the array<int, SomeEnum> docblock.
            $isEnum = $this->registry[$name]['kind'] === 'enum';
            $class = $this->registry[$name]['class'];
            $this->noteClassRef($class);

            return new ResolvedType($class, isEnum: $isEnum, classRefs: [$class]);
        }

        $this->warnings[sprintf(
            '%s: $ref "%s" does not resolve to a generated type and degrades to mixed with presence-only validation.',
            $this->warningContext,
            $pointer,
        )] = true;

        return new ResolvedType('mixed');
    }

    private function resolveArray(Schema $schema, string $nameHint, int $depth, bool $nullable, string $variant = 'all'): ResolvedType
    {
        $items = $schema->items;

        if (! $items instanceof Schema && ! $items instanceof Reference) {
            return new ResolvedType('array', $nullable, 'array<int, mixed>');
        }

        $itemType = $this->resolveType($items, $nameHint.'Item', $depth + 1, $variant);

        // A DataCollectionOf argument must be a single `Foo::class` naming a Data
        // class. A union item ('GadgetAlphaData|GadgetBetaData', 'string|int')
        // would render the invalid `#[DataCollectionOf(A|B::class)]`, which php -l
        // silently accepts (operator precedence parses it as `A | (B::class)`) but
        // is semantically wrong. A backed-enum item is a native PHP enum, not a
        // Data class, so `#[DataCollectionOf(SomeEnum::class)]` is also invalid
        // (the attribute expects class-string<BaseData>, failing PHPStan max);
        // spatie hydrates the enum from the typed array via the docblock alone.
        // For either case, emit a plain typed array with an `array<int, T>`
        // docblock and no collection attribute instead.
        $dataCollectionOf = $this->isDataClass($itemType) && ! $itemType->isUnion && ! $itemType->isEnum
            ? $itemType->declaration
            : null;

        // The element's documented type is its richer `@var` form when it carries
        // one, falling back to the bare declaration. This covers three cases with
        // one rule: an undiscriminated object-union item declares `mixed` but keeps
        // the variant union in its docType (issue #31, so the docblock reads
        // `array<int, GadgetAlphaData|GadgetBetaData>` not `array<int, mixed>`); a
        // nested array/map item declares `array` but carries its own generic
        // docType (so the docblock reads `array<int, array<int, int>>` not the
        // PHPStan-rejected `array<int, array>`); and a plain scalar/Data item has
        // no docType and uses its declaration.
        $itemDoc = $itemType->docType ?? $itemType->declaration;

        return new ResolvedType(
            'array',
            $nullable,
            'array<int, '.$itemDoc.'>',
            $itemType->imports,
            $dataCollectionOf,
            classRefs: $itemType->classRefs,
        );
    }

    private function resolveInlineObject(Schema $schema, string $nameHint, int $depth, bool $nullable, string $variant = 'all'): ResolvedType
    {
        $className = $this->names->reserve($this->withSuffix($nameHint));

        // A nested inline class follows the class that spawned it (issue #93),
        // so a holder's tag group propagates to its whole inline subtree.
        $this->fileGroups[$className] = $this->currentScopeGroup();

        // Reserve the slot before recursing so nested cycles cannot reuse it.
        $this->files[$className] = new GeneratedFile($className, '');
        $this->emitData($className, $schema, $depth, $variant);

        return new ResolvedType($className, $nullable, classRefs: [$className]);
    }

    private function emitEnum(string $className, Schema $schema): void
    {
        // emitEnum only runs for a backed-enum component (isEnum), whose values
        // are all int or string; filtering floats here also narrows the type for
        // the backing/case helpers, which a native PHP enum cannot back on a float.
        $values = [];
        foreach ($this->enumValues($schema) as $value) {
            if (is_int($value) || is_string($value)) {
                $values[] = $value;
            }
        }

        $backing = $this->enumBacking($values);
        $cases = new UniqueNames;
        $lines = [];

        foreach ($values as $value) {
            $caseName = $this->enumCaseName($value, $backing);
            $caseName = $cases->reserve($caseName);
            $literal = $backing === 'int' ? (string) (int) $value : "'".$this->escapeSingleQuoted((string) $value)."'";
            $lines[] = '    case '.$caseName.' = '.$literal.';';
        }

        $body = implode("\n", $lines);

        // A deprecated enum component carries a class-level `@deprecated` so the
        // generated enum gets the same IDE/PHPStan deprecation signal as a Data
        // class.
        $deprecationTag = $this->deprecationTag($schema);
        $docBlock = $deprecationTag !== null ? '/**'."\n".' * '.$deprecationTag."\n".' */'."\n" : '';

        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->namespaceFor($className).";\n\n".$docBlock.'enum '.$className.': '.$backing."\n{\n".$body."\n}\n";

        $this->files[$className] = new GeneratedFile($className, $code, $this->fileGroups[$className] ?? null);
    }

    /**
     * @param  list<string|int>  $values
     */
    private function enumBacking(array $values): string
    {
        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return 'string';
            }
        }

        return 'int';
    }

    private function enumCaseName(string|int $value, string $backing): string
    {
        if ($backing === 'int') {
            $int = (int) $value;

            return $int < 0 ? 'ValueMinus'.abs($int) : 'Value'.$int;
        }

        $name = PhpIdentifier::toClassName((string) $value);

        return $name === '_' ? 'Value' : $name;
    }

    /**
     * The usable scalar values of an `enum`: strings, ints, floats, and bools.
     * Floats are included so a `{type: number, enum: [1.5, 2.5]}` schema still
     * emits a `Rule::in([1.5, 2.5])` constraint instead of silently accepting any
     * number. Bools are included so a mixed-type enum
     * (`["one", 2, true, 3.5]`) keeps its boolean member in `Rule::in(...)`
     * rather than silently dropping it (a spec-valid `true` would otherwise be
     * false-rejected). Neither a float nor a bool can back a native PHP enum (PHP
     * backs only int/string), so isEnum() screens both out of the backed-enum
     * path separately, leaving them to the Data-class-with-`Rule::in` fallback.
     *
     * @return list<string|int|float|bool>
     */
    private function enumValues(Schema $schema): array
    {
        $result = [];
        foreach ($this->asArray($schema->enum) as $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Whether a component schema should become a native PHP backed enum. It must
     * be an enum with usable values, no named properties, and crucially every
     * value must be int or string: PHP backed enums cannot back a float or a bool.
     * A float-bearing or bool-bearing enum is therefore NOT a backed enum here; it
     * falls through to a Data class that carries the `Rule::in` constraint instead
     * (see isScalarEnumComponent / emitData), which keeps the bool/float member in
     * the membership rule rather than dropping it.
     */
    private function isEnum(Schema $schema): bool
    {
        if (! $this->notEmptyArray($schema->enum)) {
            return false;
        }

        if ($this->notEmptyArray($schema->properties)) {
            return false;
        }

        $values = $this->enumValues($schema);
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (is_float($value) || is_bool($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a schema is a scalar enum that has no named properties and did
     * NOT qualify as a native backed enum (isEnum). The only such case is an
     * enum carrying a float value, which a backed enum cannot represent. Such a
     * component is wrapped in a single-`value` Data class so the `Rule::in`
     * constraint is still enforced rather than emitting an empty class.
     */
    private function isScalarEnumComponent(Schema $schema): bool
    {
        if (! $this->notEmptyArray($schema->enum)) {
            return false;
        }

        if ($this->notEmptyArray($schema->properties)) {
            return false;
        }

        if ($this->isEnum($schema)) {
            return false;
        }

        return $this->enumValues($schema) !== [];
    }

    private function hasReadWriteFlags(Schema $schema): bool
    {
        foreach ($this->objectProperties($schema) as $property) {
            if ($this->isReadOnly($property) || $this->isWriteOnly($property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a diagnostic when a property schema carries a non-standard
     * per-property `required` key (a boolean `required` set on the property
     * object itself). OpenAPI 3.x ignores this: a property is required only when
     * the OWNING schema's `required: [...]` array lists it. cebe keeps the
     * stray boolean in the property's serialized data (the schema-level array,
     * by contrast, is always a list of strings), so we read it from there.
     *
     * The detection is intentionally narrow: only a boolean value triggers it,
     * so a legitimate schema-level `required` array nested inside a property
     * (itself an object schema) is never mistaken for the non-standard key.
     */
    private function warnPerPropertyRequired(string $schemaName, string $propertyName, Schema|Reference $propertySchema): void
    {
        if (! $propertySchema instanceof Schema) {
            return;
        }

        $serialized = (array) $propertySchema->getSerializableData();

        if (! array_key_exists('required', $serialized) || ! is_bool($serialized['required'])) {
            return;
        }

        $this->warnings[sprintf(
            'Property "%s" on schema "%s" has a non-standard per-property "required" key, which OpenAPI ignores. '
            .'Use the schema-level "required" array instead. This field is generated as optional.',
            $propertyName,
            $schemaName,
        )] = true;
    }

    private function isReadOnly(Schema|Reference $schema): bool
    {
        return $schema instanceof Schema && $schema->readOnly === true;
    }

    private function isWriteOnly(Schema|Reference $schema): bool
    {
        return $schema instanceof Schema && $schema->writeOnly === true;
    }

    /**
     * Whether a schema (a component or a single property) is marked
     * `deprecated: true`. cebe types `deprecated` as a strict bool defaulting to
     * false, so a missing flag never reads as deprecated.
     */
    private function isDeprecated(Schema|Reference $schema): bool
    {
        return $schema instanceof Schema && $schema->deprecated === true;
    }

    /**
     * The deprecation docblock line for a deprecated schema (the literal
     * "at-deprecated" tag, optionally followed by a reason), including a short
     * reason when the spec supplies one via the vendor extension
     * `x-deprecated-reason` or `x-deprecation-reason`. Returns null when the
     * schema is not deprecated. The reason is spec-derived (untrusted), so it is
     * run through docblockSafe() before being embedded in the comment.
     */
    private function deprecationTag(Schema|Reference $schema): ?string
    {
        if (! $this->isDeprecated($schema)) {
            return null;
        }

        $reason = $this->deprecationReason($schema);

        return $reason !== null ? '@deprecated '.$reason : '@deprecated';
    }

    /**
     * The deprecation reason text from a vendor extension, sanitized, or null
     * when none is present or it sanitizes to empty. Both the singular
     * `x-deprecated-reason` and the alternate `x-deprecation-reason` spellings
     * are accepted; the first non-empty one wins (sorted-key independent: the two
     * are checked in a fixed order for determinism).
     */
    private function deprecationReason(Schema|Reference $schema): ?string
    {
        if (! $schema instanceof Schema) {
            return null;
        }

        $serialized = (array) $schema->getSerializableData();

        foreach (['x-deprecated-reason', 'x-deprecation-reason'] as $key) {
            $value = $serialized[$key] ?? null;
            if (is_string($value)) {
                $safe = $this->docblockSafe($value);
                if ($safe !== '') {
                    return $safe;
                }
            }
        }

        return null;
    }

    /**
     * Neutralize spec-derived free text before it is placed inside a `/** ... *\/`
     * docblock. Two hazards: a literal `*\/` would close the comment early and let
     * the rest of the value inject raw PHP, and newlines or other control
     * characters would let a value forge extra doc lines or break out. Every `*\/`
     * becomes `* /` and all control characters (newlines, tabs, etc.) collapse to
     * a single space. Mirrors the server scaffold's docblockSafe(): the OpenAPI
     * spec is untrusted input. (The two live in separate emitter layers that
     * Deptrac keeps apart, so the small duplication is intentional.)
     */
    private function docblockSafe(string $value): string
    {
        $value = str_replace('*/', '* /', $value);
        $value = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value);

        return trim($value);
    }

    private function isDataClass(ResolvedType $type): bool
    {
        return $type->declaration !== 'mixed'
            && $type->declaration !== 'array'
            && ! in_array($type->declaration, self::SCALARS, true);
    }

    /**
     * @return list<string>
     */
    private function normalizeTypes(Schema $schema): array
    {
        $raw = $schema->type;
        $types = [];

        if (is_array($raw)) {
            $types = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $types = [$raw];
        }

        $filtered = [];
        foreach ($types as $type) {
            if (is_string($type) && $type !== 'null') {
                $filtered[] = $type;
            }
        }

        return $filtered;
    }

    private function isNullable(Schema $schema): bool
    {
        if ($schema->nullable === true) {
            return true;
        }

        $raw = $schema->type;

        return is_array($raw) && in_array('null', $raw, true);
    }

    /**
     * The explicit `additionalProperties` value schema for a map, or null when
     * the schema is not an explicitly-declared map.
     *
     * cebe defaults `additionalProperties` to boolean `true` on every object, so
     * the in-memory value cannot tell an explicit `additionalProperties: true`
     * apart from a plain object that never declared it. getSerializableData()
     * keeps only attributes the spec actually set, so its key presence is the
     * honest signal of intent. We treat the map as present only when the spec
     * set the key to a value other than `false`.
     *
     * Returns the value Schema/Reference for a typed map, or the schema itself
     * (as a marker) for `additionalProperties: true`. Returns null otherwise.
     */
    private function additionalPropertiesSchema(Schema $schema): Schema|Reference|true|null
    {
        $serialized = (array) $schema->getSerializableData();
        if (! array_key_exists('additionalProperties', $serialized)) {
            return null;
        }

        $value = $schema->additionalProperties;

        if ($value === false) {
            return null;
        }

        if ($value instanceof Schema || $value instanceof Reference) {
            return $value;
        }

        // additionalProperties: true (untyped map).
        return true;
    }

    /**
     * Whether the schema explicitly declares `additionalProperties: false`, the
     * closed-object marker (issue #30). cebe defaults `additionalProperties` to
     * boolean `true` on every object, so the in-memory value alone cannot tell an
     * explicit `false` apart from an unset default; getSerializableData() keeps
     * only keys the spec actually set, so its presence is the honest signal of
     * intent. Returns true only when the spec set the key to literal `false`.
     */
    private function declaresClosedObject(Schema $schema): bool
    {
        $serialized = (array) $schema->getSerializableData();

        return array_key_exists('additionalProperties', $serialized)
            && $schema->additionalProperties === false;
    }

    /**
     * The closed-object rule expression for a schema that declared
     * `additionalProperties: false`, or null when enforcement must be skipped.
     *
     * `patternProperties` legally admits keys beyond the declared property set
     * even under `additionalProperties: false` (issue #65), so its patterns are
     * passed to the rule as a second, pattern allow-list: a matching key is
     * admitted (its value schema is not validated, only key admission). Each
     * pattern is delimited exactly like the `pattern` rule (PCRE, no
     * ECMA-262 dialect translation) and verified to compile; if any pattern
     * does not compile as PCRE, the rule cannot tell legal keys apart, so the
     * sound fallback is to skip closed-object enforcement for this schema
     * entirely. Both the relaxation and the skip are surfaced as build
     * warnings: false-rejecting valid data is worse than under-validating.
     *
     * @param  list<string>  $wireNames  the declared wire (input) names
     */
    private function closedObjectRule(string $schemaName, array $wireNames, Schema $schema): ?string
    {
        $patterns = $this->patternPropertyPatterns($schema);

        if ($patterns === []) {
            return 'new NoUnknownPropertiesRule('.$this->stringListLiteral($wireNames).')';
        }

        $delimited = [];
        foreach ($patterns as $pattern) {
            $candidate = $this->delimitedPattern($pattern);
            if (! $this->compilesAsPcre($candidate)) {
                $this->warnings[sprintf(
                    'Schema "%s" declares patternProperties with a pattern that is not valid PCRE (%s); '
                    .'closed-object enforcement (additionalProperties: false) is skipped for this schema '
                    .'so spec-legal keys are never falsely rejected.',
                    $schemaName,
                    (string) json_encode($pattern),
                )] = true;

                return null;
            }
            $delimited[] = $candidate;
        }

        $this->warnings[sprintf(
            'Schema "%s" combines additionalProperties: false with patternProperties. '
            .'Keys matching a pattern are accepted by the closed-object rule, but their value schemas are not validated.',
            $schemaName,
        )] = true;

        return 'new NoUnknownPropertiesRule('
            .$this->stringListLiteral($wireNames)
            .', '
            .$this->stringListLiteral($delimited)
            .')';
    }

    /**
     * Whether a delimited pattern compiles as PCRE. The spec dialect is
     * ECMA-262 and PHP's is PCRE, and the pattern is untrusted spec input, so
     * compilation is probed before the pattern is embedded in generated code.
     * The probe's PHP warning is swallowed by a scoped no-op error handler
     * (plain @-suppression would still surface as a PHPUnit test warning).
     */
    private function compilesAsPcre(string $delimited): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($delimited, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The `patternProperties` patterns a schema declares, in spec order. cebe
     * has no typed attribute for `patternProperties` (a JSON Schema keyword
     * OpenAPI 3.1 admits), so it is read from the serialized data, where cebe
     * keeps unknown keys verbatim. The value schemas are intentionally ignored:
     * only key admission is derived from them (see closedObjectRule()).
     *
     * @return list<string>
     */
    private function patternPropertyPatterns(Schema $schema): array
    {
        $serialized = (array) $schema->getSerializableData();
        $raw = $serialized['patternProperties'] ?? null;
        if (is_object($raw)) {
            $raw = (array) $raw;
        }
        if (! is_array($raw)) {
            return [];
        }

        $patterns = [];
        foreach (array_keys($raw) as $pattern) {
            $patterns[] = (string) $pattern;
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Whether a component schema is a pure map: an object whose only content is
     * `additionalProperties` (a typed, ref, or untyped value), with no named
     * `properties` and no composition keyword. Such a schema is a typed array,
     * not a Data class. A mixed object (named properties AND
     * additionalProperties) is NOT a pure map: its named properties are emitted
     * normally and the dynamic overflow is documented as not captured.
     */
    private function isPureMap(Schema $schema): bool
    {
        if ($this->additionalPropertiesSchema($schema) === null) {
            return false;
        }

        if ($this->notEmptyArray($schema->properties)) {
            return false;
        }

        if ($this->notEmptyArray($schema->allOf)
            || $this->notEmptyArray($schema->oneOf)
            || $this->notEmptyArray($schema->anyOf)
        ) {
            return false;
        }

        if ($this->notEmptyArray($schema->enum)) {
            return false;
        }

        $types = $this->normalizeTypes($schema);
        $primary = $types[0] ?? null;

        // Only an object (or an untyped schema that is map-shaped) is a map.
        return $primary === 'object' || $primary === null;
    }

    /**
     * Promote chained aliases to the alias set. A registry component that is a
     * thin `allOf: [{$ref: T}]` (no own properties) is an alias when its target T
     * is itself an alias (direct or already-promoted). Such a component is then
     * removed from the registry and recorded in $aliasSchemas, so a `$ref` to it
     * resolves to the underlying type rather than an empty merged Data class.
     *
     * Iterated to a fixpoint so a multi-level chain (A -> B -> C, all allOf-refs
     * terminating at a scalar) promotes every link: each pass promotes the links
     * whose immediate target became an alias in the previous pass. A chain whose
     * target is an object Data class is never promoted and stays a Data class.
     * Bounded by the registry size (each pass promotes at least one, or stops).
     *
     * @param  array<string, Schema>  $schemas
     */
    private function promoteChainedAliases(array $schemas): void
    {
        do {
            $promoted = false;

            foreach ($schemas as $name => $schema) {
                if (isset($this->aliasSchemas[$name]) || ! isset($this->registry[$name])) {
                    continue;
                }

                $ref = $this->bareAllOfRef($schema);
                if ($ref === null) {
                    continue;
                }

                $target = $this->refName($ref->getReference());
                if ($target === null || ! isset($this->aliasSchemas[$target])) {
                    continue;
                }

                // The target is an alias: this thin wrapper is an alias too.
                unset($this->registry[$name], $this->files[$name]);
                $this->aliasSchemas[$name] = $schema;
                $promoted = true;
            }
        } while ($promoted);
    }

    /**
     * Whether a top-level component schema is a non-object ALIAS: a scalar
     * (`{type: string, format: date-time}`), an array (`{type: array, items}`),
     * or a oneOf/anyOf union, with NO object `properties`. Such a component is a
     * type alias, not a Data class, and a `$ref` to it resolves to the
     * underlying type at the use site rather than an empty class.
     *
     * The distinction the issue cares about: a `type: object` component with no
     * properties (or `properties: {}`) is a LEGITIMATELY EMPTY OBJECT and must
     * still emit an (empty) Data class, so it is NOT an alias here. This method
     * targets only scalar/array/composition components.
     *
     * Excluded (handled elsewhere): pure-map components (typed arrays already),
     * enum components (native backed enums), and float-enum scalar components
     * (wrapped in a single-`value` Data class to keep the Rule::in constraint).
     */
    private function isNonObjectAlias(Schema $schema): bool
    {
        // A component with named properties is an object Data class, not an alias.
        if ($this->notEmptyArray($schema->properties)) {
            return false;
        }

        // Pure-map and enum components have their own handling; never alias them.
        if ($this->isPureMap($schema) || $this->isEnum($schema) || $this->isScalarEnumComponent($schema)) {
            return false;
        }

        // A oneOf/anyOf component is a union alias. allOf is an object merge, not
        // a non-object alias, so it is excluded (it stays a Data class).
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return true;
        }

        // allOf is an object merge, so a plain allOf component stays a Data class.
        // The one exception, a sole-`allOf`-of-one-`$ref` whose target is itself a
        // non-object alias (the chained-alias shape `A: {allOf: [{$ref: B}]}`,
        // B scalar/array/union), is promoted to an alias in a later pass once the
        // target's classification is known (see promoteChainedAliases): it cannot
        // be decided here because the registry/alias caches are not built yet.
        if ($this->notEmptyArray($schema->allOf)) {
            return false;
        }

        $types = $this->normalizeTypes($schema);
        $primary = $types[0] ?? null;

        // A scalar (string/int/float/bool) or array component is a non-object
        // alias. A multi-type scalar array (`type: ["string","integer"]`) is a
        // scalar union and also aliases. `object` (or untyped/empty, which is a
        // legitimately empty object after the pure-map check above) is NOT.
        if ($primary === 'array') {
            return true;
        }

        foreach ($types as $type) {
            if (! isset(self::SCALARS[$type])) {
                return false;
            }
        }

        return $types !== [];
    }

    /**
     * Resolve a non-object alias component to its underlying type and cache it in
     * $aliasTypes. Resolution is transitive and cycle-guarded: the alias schema
     * is run through resolveType, which consults the registry and the alias
     * caches, so an alias whose type is a `$ref` to another alias chains through.
     *
     * The guard reserves the alias name before recursing; a cyclic alias chain
     * (A aliases B, B aliases A, which no sane spec writes but a hostile one
     * might) resolves to `mixed` rather than recursing forever.
     *
     * @param  array<string, true>  $seen  alias names already being resolved
     */
    private function resolveAlias(string $name, array $seen = []): ResolvedType
    {
        if (isset($this->aliasTypes[$name])) {
            return $this->aliasTypes[$name];
        }

        if (isset($seen[$name])) {
            $this->warnings[sprintf(
                'Component schema "%s" is part of a cyclic alias chain and degrades to mixed with presence-only validation.',
                $name,
            )] = true;

            return new ResolvedType('mixed');
        }

        $schema = $this->aliasSchemas[$name] ?? null;
        if ($schema === null) {
            return new ResolvedType('mixed');
        }

        $seen[$name] = true;
        $this->aliasSeen = $seen;

        // Aliases resolve lazily, possibly mid-emission of another schema, so
        // the warning context is restored afterwards: a degradation inside the
        // alias is attributed to the alias component, not to whichever use
        // site happened to trigger the resolution first.
        $previousContext = $this->warningContext;
        $this->warningContext = sprintf('Schema "%s"', $name);

        // A nested class spawned from inside the alias (an array-of-inline-
        // object alias) follows the alias component's own tag group (issue
        // #93); the refs recorded here are discarded, because use sites replay
        // them from the cached classRefs.
        $this->pushGroupScope($this->groupForSchema($name));

        try {
            $resolved = $this->resolveType($schema, PhpIdentifier::toClassName($name), 0);
        } finally {
            $this->popRefScope();
            $this->warningContext = $previousContext;
            $this->aliasSeen = [];
        }

        return $this->aliasTypes[$name] = $resolved;
    }

    /**
     * Cycle guard for the alias being resolved, so a chained alias $ref reached
     * through resolveReference does not recurse forever on a cyclic chain.
     *
     * @var array<string, true>
     */
    private array $aliasSeen = [];

    /**
     * Resolve a pure-map schema to its array type: `array<string, X>` where X is
     * derived from the `additionalProperties` value schema. Scalar values map to
     * their PHP type, a `$ref` value maps to the referenced Data class, and
     * `true`/untyped maps to `mixed`.
     */
    private function mapType(Schema $schema, string $nameHint, int $depth, string $variant): ResolvedType
    {
        $value = $this->additionalPropertiesSchema($schema);
        $nullable = $this->isNullable($schema);

        if ($value === true || $value === null) {
            return new ResolvedType('array', $nullable, 'array<string, mixed>', [], null, false, true);
        }

        $valueType = $this->resolveType($value, $nameHint.'Value', $depth + 1, $variant);

        // The value's documented type is its richer `@var` form when it carries one
        // (a nested array/map value declares `array` but has its own generic
        // docType, so the map reads `array<string, array<int, T>>` rather than the
        // PHPStan-rejected `array<string, array>`), falling back to the bare
        // declaration for a scalar/Data value with no docType.
        $valueDoc = $valueType->docType ?? $valueType->declaration;

        return new ResolvedType(
            'array',
            $nullable,
            'array<string, '.$valueDoc.'>',
            $valueType->imports,
            null,
            false,
            true,
            classRefs: $valueType->classRefs,
        );
    }

    /**
     * The property map for a schema, with any `allOf` members merged in.
     *
     * @return array<string, Schema|Reference>
     */
    private function objectProperties(Schema $schema): array
    {
        return $this->mergeAllOf($schema)['properties'];
    }

    /**
     * The required-property names for a schema, with any `allOf` members merged in.
     *
     * @return list<string>
     */
    private function requiredNames(Schema $schema): array
    {
        return $this->mergeAllOf($schema)['required'];
    }

    /**
     * Flatten an object schema that composes other schemas via `allOf` into a
     * single property map and required list. Members may be inline objects or
     * `$ref`s to components; refs are resolved through the component registry.
     * Members that themselves use `allOf` are merged first (recursively), and
     * `allOf` nested inside a property is handled when that property is later
     * resolved as its own object.
     *
     * Ordering: first-seen wins for position, scanning the schema's own
     * properties first, then each member in source order. The class therefore
     * lists own properties, then member1's, then member2's, deduplicated.
     *
     * Conflict resolution (same property name from several sources): the value
     * is overridden by precedence, lowest to highest = earlier member, later
     * member, the schema's own property. So a later `allOf` member overrides an
     * earlier one, and an explicit own-level property overrides every member.
     * The first-seen position is kept even when a later source wins the value.
     *
     * @param  array<string, true>  $seen  component names already being merged (keyed for O(1) cycle checks)
     * @return array{properties: array<string, Schema|Reference>, required: list<string>}
     */
    private function mergeAllOf(Schema $schema, array $seen = []): array
    {
        $ownProperties = $this->localProperties($schema);
        $ownRequired = $this->localRequired($schema);

        $members = $schema->allOf;
        if (! is_array($members) || $members === []) {
            return ['properties' => $ownProperties, 'required' => $ownRequired];
        }

        // Position order: own properties first, then members in source order.
        $order = array_keys($ownProperties);

        // Value precedence, lowest to highest: member1, member2, ..., own.
        // Build the winning value per name by overwriting in precedence order.
        $values = [];
        $required = [];

        foreach ($members as $member) {
            $resolved = $this->resolveMemberSchema($member, $seen);
            if ($resolved === null) {
                $this->warnUnmergedAllOfMember($member, $seen);

                continue;
            }

            [$memberSchema, $memberSeen] = $resolved;
            $merged = $this->mergeAllOf($memberSchema, $memberSeen);

            foreach ($merged['properties'] as $name => $property) {
                if (! array_key_exists($name, $values) && ! array_key_exists($name, $ownProperties)) {
                    $order[] = $name;
                }
                $values[$name] = $property;
            }

            foreach ($merged['required'] as $name) {
                $required[] = $name;
            }
        }

        // Own properties win the value over every member.
        foreach ($ownProperties as $name => $property) {
            $values[$name] = $property;
        }

        foreach ($ownRequired as $name) {
            $required[] = $name;
        }

        $properties = [];
        foreach ($order as $name) {
            $properties[$name] = $values[$name];
        }

        return ['properties' => $properties, 'required' => $this->dedupe($required)];
    }

    /**
     * Surface an `allOf` member that resolveMemberSchema() could not resolve
     * (issue #67): its properties are silently absent from the composed class,
     * which is exactly the hollow-output failure mode the warnings channel
     * exists for. Two skips stay silent by design: a cycle-guard hit (merging
     * a recursive chain once is the sane semantics, not information loss) and
     * a member that is a non-object alias or pure-map component (a scalar,
     * array, or map carries no properties to merge).
     *
     * @param  array<string, true>  $seen  component names already being merged
     */
    private function warnUnmergedAllOfMember(Schema|Reference $member, array $seen): void
    {
        if (! $member instanceof Reference) {
            return;
        }

        $pointer = $member->getReference();
        $name = $this->refName($pointer);

        if ($name === null) {
            $this->warnings[sprintf(
                '%s: allOf member $ref "%s" is external or not a #/components/schemas pointer; its properties are not merged into the composed class. Bundle external references into one document before generating.',
                $this->warningContext,
                $pointer,
            )] = true;

            return;
        }

        if (isset($seen[$name]) || isset($this->aliasSchemas[$name]) || isset($this->mapSchemas[$name])) {
            return;
        }

        $this->warnings[sprintf(
            '%s: allOf member $ref "%s" does not resolve to a component schema; its properties are not merged into the composed class.',
            $this->warningContext,
            $pointer,
        )] = true;
    }

    /**
     * Whether a merged schema is nullable: the composing schema or any allOf
     * member (recursively) declares nullability. A single nullable member is
     * enough, matching how `allOf` constrains the combined value.
     *
     * @param  array<string, true>  $seen  component names already visited (keyed for O(1) cycle checks)
     */
    private function mergedNullable(Schema $schema, array $seen = []): bool
    {
        if ($this->isNullable($schema)) {
            return true;
        }

        $members = $schema->allOf;
        if (! is_array($members)) {
            return false;
        }

        foreach ($members as $member) {
            $resolved = $this->resolveMemberSchema($member, $seen);
            if ($resolved === null) {
                continue;
            }

            [$memberSchema, $memberSeen] = $resolved;
            if ($this->mergedNullable($memberSchema, $memberSeen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the "alias" shape: a schema whose only object content is an `allOf`
     * with exactly one member, that member being a `$ref`, and the schema
     * carrying no own properties. Such a schema is just the referenced type with
     * extra annotations (typically a description), so it resolves to the
     * referenced type rather than a merged copy. Returns the reference, or null
     * when the shape does not match.
     *
     * Structural only: it does not consult the registry, so it can run during the
     * alias-classification pass (before the registry is fully built) and so a
     * chain to a non-object alias target (which is never in the registry) is
     * still recognized. An unknown target resolves to `mixed` downstream via
     * resolveReference, the same degenerate result an empty merged class gave.
     */
    private function bareAllOfRef(Schema $schema): ?Reference
    {
        if ($this->notEmptyArray($schema->properties)) {
            return null;
        }

        $members = $schema->allOf;
        if (! is_array($members) || count($members) !== 1) {
            return null;
        }

        $member = $members[0];
        if (! $member instanceof Reference) {
            return null;
        }

        return $this->refName($member->getReference()) !== null ? $member : null;
    }

    /**
     * Resolve one `allOf` member to a concrete schema. Inline schemas pass
     * through. A `$ref` to a component schema is looked up in the registry,
     * guarded against cycles by tracking the component names already in flight.
     *
     * @param  array<string, true>  $seen
     * @return array{0: Schema, 1: array<string, true>}|null resolved schema + updated cycle guard, or null if unresolvable
     */
    private function resolveMemberSchema(Schema|Reference $member, array $seen): ?array
    {
        if ($member instanceof Schema) {
            return [$member, $seen];
        }

        $name = $this->refName($member->getReference());

        if ($name === null || isset($seen[$name]) || ! isset($this->registry[$name])) {
            return null;
        }

        $target = $this->registry[$name]['schema'];
        $seen[$name] = true;

        return [$target, $seen];
    }

    /**
     * @return array<string, Schema|Reference>
     */
    private function localProperties(Schema $schema): array
    {
        $result = [];
        foreach ($this->asArray($schema->properties) as $name => $property) {
            if ($property instanceof Schema || $property instanceof Reference) {
                $result[(string) $name] = $property;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function localRequired(Schema $schema): array
    {
        $result = [];
        foreach ($this->asArray($schema->required) as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function dedupe(array $names): array
    {
        return array_values(array_unique($names));
    }

    /**
     * The `const` keyword (JSON Schema / OpenAPI 3.1) constrains a value to a
     * single literal. cebe does not model `const` as a typed attribute, so it
     * lands in the serialized overflow. We read it from there and only accept
     * scalar literals (string/int) we can both type and enforce with Rule::in;
     * anything else (array/object/bool/float/null const) yields null and the
     * schema is handled by the normal type path.
     *
     * Returns a single-element list so callers can distinguish "no const"
     * (null) from "const present" ([value]) even for falsy values.
     *
     * @return array{0: string|int}|null
     */
    private function constValue(Schema $schema): ?array
    {
        $serialized = (array) $schema->getSerializableData();

        if (! array_key_exists('const', $serialized)) {
            return null;
        }

        $value = $serialized['const'];

        if (is_string($value) || is_int($value)) {
            return [$value];
        }

        return null;
    }

    private function refName(string $pointer): ?string
    {
        if (! str_starts_with($pointer, '#/components/schemas/')) {
            return null;
        }

        $parts = explode('/', $pointer);
        $last = end($parts);

        return $last === '' ? null : $last;
    }

    private function withSuffix(string $base): string
    {
        $suffix = $this->options->dataSuffix;

        if ($suffix === '' || str_ends_with($base, $suffix)) {
            return $base;
        }

        return $base.$suffix;
    }

    private function stripSuffix(string $className): string
    {
        $suffix = $this->options->dataSuffix;

        if ($suffix !== '' && str_ends_with($className, $suffix)) {
            return substr($className, 0, -strlen($suffix));
        }

        return $className;
    }

    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * A `['a', 'b']` PHP array literal of strings for the closed-object rule's
     * allow-lists (declared wire names, delimited patternProperties patterns).
     * Each item is single-quote-escaped (both come from untrusted spec input),
     * mirroring how MapName renders a wire name.
     *
     * @param  list<string>  $values
     */
    private function stringListLiteral(array $values): string
    {
        $items = array_map(
            fn (string $value): string => "'".$this->escapeSingleQuoted($value)."'",
            array_values(array_unique($values)),
        );

        return '['.implode(', ', $items).']';
    }

    private function notEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
