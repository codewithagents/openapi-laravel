<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

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
     * The per-run mutable context (issue #109): the component registry, the
     * alias caches, the emitted file buckets, the diagnostics channel, and
     * the grouped-layout bookkeeping. Replaced wholesale at the start of
     * every generate() run, which retires the old field-by-field reset.
     */
    private GenerationState $state;

    /**
     * Derives the validation rules for the current run; recreated together
     * with the state so its registry lookups always see the live run.
     */
    private RulesBuilder $rules;

    /**
     * Resolves schemas to PHP types for the current run; recreated together
     * with the state. Holds the emitData() callback through which an inline
     * object schema spawns its nested Data class mid-resolution.
     */
    private TypeResolver $types;

    /**
     * Emits backed enums for the current run; recreated together with the
     * state so the rendered files land in the live run's bucket.
     */
    private EnumEmitter $enums;

    /**
     * Renders the generated PHP source (properties, imports, class shapes)
     * for the current run; recreated together with the state.
     */
    private ClassRenderer $renderer;

    /**
     * Synthesizes the per-operation query/body/multipart Data classes for the
     * current run; recreated together with the state. Bodies re-enter the
     * component pipeline through the emitData() callback.
     */
    private RequestDataSynthesizer $bodies;

    public function __construct(
        private readonly GeneratorOptions $options = new GeneratorOptions,
    ) {
        $this->wireCollaborators();
    }

    /**
     * Build a fresh per-run context and the collaborators bound to it. Called
     * from the constructor and at the start of every generate() run, which
     * replaces the old field-by-field reset (issue #109).
     */
    private function wireCollaborators(): void
    {
        $this->state = new GenerationState($this->options, new UniqueNames(self::RESERVED_CLASS_NAMES, caseInsensitive: true));
        $this->rules = new RulesBuilder($this->state);
        $this->types = new TypeResolver($this->state, $this->emitData(...));
        $this->enums = new EnumEmitter($this->state);
        $this->renderer = new ClassRenderer($this->state);
        $this->bodies = new RequestDataSynthesizer(
            $this->state,
            $this->types,
            $this->rules,
            $this->renderer,
            $this->emitData(...),
            $this->hasReadWriteFlags(...),
        );
    }

    /**
     * @return array<string, GeneratedFile> class name => file, ordered by class name
     */
    public function generate(OpenApiDocument $document): array
    {
        $this->wireCollaborators();

        // Tag-grouped data layout (issue #93, the only layout): attribute each
        // component schema to the tag group that solely owns it, via the same
        // transitive walk as the subset closure. Computed here from the
        // document itself so EVERY caller gets the grouped layout; the options
        // map is an injection seam for unit tests of the emission side.
        $this->state->schemaGroups = $this->options->schemaGroups
            ?? (new SchemaClosure)->attributeByTag($document);

        $schemas = $this->componentSchemas($document);
        ksort($schemas);

        // Find discriminated object unions before classifying schemas: a union
        // base (a oneOf/anyOf of object $refs plus a discriminator) becomes an
        // abstract Data class rather than a non-object alias, and its variants
        // extend it. Built from the same component map so it is deterministic.
        $this->state->discriminators = new DiscriminatorRegistry($schemas);

        // Surface why any discriminated union degraded to presence-only (a
        // non-object member, a multi-base conflict, or a base left with no
        // claimable variants) through the same diagnostics channel as the other
        // silent-information-loss warnings.
        foreach ($this->state->discriminators->warnings() as $warning) {
            $this->state->warnings[$warning] = true;
        }

        // The inline-union form (#38) has no named component per variant: the
        // registry synthesized a deterministic, collision-safe name and schema for
        // each inline member. Merge those into the schema map so the rest of the
        // pipeline (registration, emission, $ref resolution) treats them exactly
        // like a named variant component. Sorted keys keep output deterministic.
        foreach ($this->state->discriminators->syntheticVariants() as $variantName => $variantSchema) {
            $schemas[$variantName] = $variantSchema;
        }
        ksort($schemas);

        foreach ($schemas as $name => $schema) {
            // A discriminated-union base is a Data class (an abstract
            // PropertyMorphableData), even though it is structurally a
            // oneOf/anyOf alias. Register it as such before the alias check
            // below would otherwise skip it.
            if ($this->state->discriminators->isBase($name)) {
                $class = $this->state->names->reserve($this->options->withSuffix(PhpIdentifier::toClassName($name)));
                $this->state->registry[$name] = ['class' => $class, 'kind' => 'data', 'schema' => $schema];
                $this->state->fileGroups[$class] = $this->state->groupForSchema($name);

                continue;
            }

            // A pure-map component (only additionalProperties, no named
            // properties) is not a Data class: it is a typed array. Record it as
            // a map alias and skip emitting an empty class. References to it
            // inline the array type at the use site.
            if (SchemaFacts::isPureMap($schema)) {
                continue;
            }

            // A non-object alias component (a scalar/array/union with no object
            // properties) is a TYPE ALIAS, not a Data class. Skip emitting an
            // empty class; the second pass resolves it to its underlying type
            // and references inline that type at the use site.
            if ($this->isNonObjectAlias($schema)) {
                continue;
            }

            $kind = SchemaFacts::isEnum($schema) ? 'enum' : 'data';
            $base = PhpIdentifier::toClassName($name);
            $class = $kind === 'data' ? $this->options->withSuffix($base) : $base;
            $class = $this->state->names->reserve($class);

            $this->state->registry[$name] = ['class' => $class, 'kind' => $kind, 'schema' => $schema];
            $this->state->fileGroups[$class] = $this->state->groupForSchema($name);
        }

        // Second classification pass: resolve each pure-map component to its
        // array type. Done after the registry is built so a map whose value is a
        // `$ref` resolves the referenced class. Sorted for determinism.
        foreach ($schemas as $name => $schema) {
            if (SchemaFacts::isPureMap($schema)) {
                $this->state->warningContext = sprintf('Schema "%s"', $name);
                $this->state->mapSchemas[$name] = $schema;
                // A nested class spawned from the map's value schema follows
                // the map component's own tag group (issue #93); the recorded
                // refs are discarded (use sites replay the cached classRefs).
                $this->state->pushGroupScope($this->state->groupForSchema($name));
                $this->state->mapAliases[$name] = $this->types->mapType($schema, PhpIdentifier::toClassName($name), 0, 'all');
                $this->state->popRefScope();
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
            if ($this->state->discriminators->isBase($name)) {
                continue;
            }
            if ($this->isNonObjectAlias($schema)) {
                $this->state->aliasSchemas[$name] = $schema;
            }
        }

        // Promote chained aliases: a registry component that is a thin
        // `allOf: [{$ref}]` whose chain terminates at a non-object alias is
        // itself an alias, not a Data class. Done now (not in the first pass)
        // because it needs the direct-alias set above to know the target's kind.
        // A chain that terminates at an object Data class stays a Data class
        // (its read/write split and server-scaffold registry entry are kept).
        $this->promoteChainedAliases($schemas);

        foreach ($this->state->aliasSchemas as $name => $schema) {
            $this->types->resolveAlias($name);
        }

        foreach ($this->state->registry as $name => $entry) {
            // One warning context per component: the read/write variants and
            // every inline nested class of this schema report a shared
            // degradation once (issue #67).
            $this->state->warningContext = sprintf('Schema "%s"', $name);

            if ($entry['kind'] === 'enum') {
                $this->enums->emitEnum($entry['class'], $entry['schema']);
            } elseif ($this->state->discriminators->isBase($name)) {
                // The abstract base of a discriminated union: only the
                // discriminator property, marked for morph, plus a match() that
                // maps each discriminator value to its variant Data class.
                $this->emitDiscriminatorBase($name, $entry['class']);
            } elseif ($this->state->discriminators->isVariant($name)) {
                // A variant extends its base, forwards the discriminator, and
                // declares only its own (non-discriminator) properties.
                $this->emitVariant($name, $entry['class'], $entry['schema']);
            } elseif ($this->hasReadWriteFlags($entry['schema'])) {
                // The spec marks fields readOnly/writeOnly: split into a read
                // variant (drops writeOnly) and a write variant (drops readOnly).
                $this->emitData($entry['class'], $entry['schema'], 0, 'read');
                $writeClass = $this->state->names->reserve($this->options->withSuffix($this->options->stripSuffix($entry['class']).'Writable'));
                $this->state->writeClasses[$name] = $writeClass;
                // The write variant is the same component, so it follows the
                // component's tag group (issue #93).
                $this->state->fileGroups[$writeClass] = $this->state->fileGroups[$entry['class']] ?? null;
                $this->emitData($writeClass, $entry['schema'], 0, 'write');
            } else {
                $this->emitData($entry['class'], $entry['schema'], 0);
            }
        }

        ksort($this->state->files);

        return $this->state->files;
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

        foreach ($this->state->registry as $name => $entry) {
            $result[$name] = [
                'dataClass' => $entry['class'],
                'writeClass' => $this->state->writeClasses[$name] ?? null,
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
        $warnings = array_keys($this->state->warnings);
        sort($warnings);

        return $warnings;
    }

    /**
     * Emit a per-operation query Data class (issue #63) for an operation's
     * `in: query` parameters. Must be called AFTER generate(); see
     * {@see RequestDataSynthesizer::generateQueryData()} for the full
     * contract. Kept on the generator so the server scaffold keeps one
     * entry point into the model pipeline.
     *
     * @param  list<ParameterNode>  $parameters  the operation's `in: query` parameters, in spec order
     * @return string|null the reserved query class name, or null when every parameter was skipped
     */
    public function generateQueryData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        return $this->bodies->generateQueryData($baseName, $operationLabel, $parameters, $tag);
    }

    /**
     * Emit a per-operation path Data class (issue #113) for an operation's
     * `in: path` parameters. Must be called AFTER generate(); see
     * {@see RequestDataSynthesizer::generatePathData()} for the full
     * contract. Kept on the generator so the server scaffold keeps one
     * entry point into the model pipeline, exactly like generateQueryData().
     *
     * @param  list<ParameterNode>  $parameters  the operation's `in: path` parameters, in spec order
     * @return string|null the reserved path class name, or null when there are no path parameters
     */
    public function generatePathData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        return $this->bodies->generatePathData($baseName, $operationLabel, $parameters, $tag);
    }

    /**
     * Emit a per-operation header Data class (issue #121) for an operation's
     * `in: header` parameters. Must be called AFTER generate(); see
     * {@see RequestDataSynthesizer::generateHeaderData()} for the full
     * contract. Kept on the generator so the server scaffold keeps one entry
     * point into the model pipeline, exactly like generateQueryData().
     *
     * @param  list<ParameterNode>  $parameters  the operation's `in: header` parameters, in spec order
     * @return string|null the reserved header class name, or null when there are no validatable header parameters
     */
    public function generateHeaderData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        return $this->bodies->generateHeaderData($baseName, $operationLabel, $parameters, $tag);
    }

    /**
     * Emit a per-operation request-body Data class (issue #76) for an inline
     * JSON request-body schema. Must be called AFTER generate(); see
     * {@see RequestDataSynthesizer::generateBodyData()} for the full contract.
     *
     * @return string|null the reserved body class name, or null when the schema cannot type a body
     */
    public function generateBodyData(string $baseName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        return $this->bodies->generateBodyData($baseName, $operationLabel, $schema, $tag);
    }

    /**
     * Emit a per-operation request-body Data class for a multipart/form-data
     * body (issue #75). Must be called AFTER generate(); see
     * {@see RequestDataSynthesizer::generateMultipartBodyData()} for the full
     * contract.
     *
     * @return string|null the reserved body class name, or null when the schema cannot type a body
     */
    public function generateMultipartBodyData(string $baseName, string $operationLabel, SchemaNode|ReferenceNode $schema, ?string $tag = null): ?string
    {
        return $this->bodies->generateMultipartBodyData($baseName, $operationLabel, $schema, $tag);
    }

    /**
     * Emit (or reuse) the SHARED Data class of a component request body whose
     * JSON schema is an inline object (issue #110). Must be called AFTER
     * generate(); see {@see RequestDataSynthesizer::generateComponentBodyData()}
     * for the full contract.
     *
     * @return string|null the shared body class name, or null when the schema cannot type a body
     */
    public function generateComponentBodyData(string $componentName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        return $this->bodies->generateComponentBodyData($componentName, $operationLabel, $schema, $tag);
    }

    /**
     * Emit (or reuse) the SHARED Data class of a multipart/form-data component
     * request body (issue #110). Must be called AFTER generate(); see
     * {@see RequestDataSynthesizer::generateComponentMultipartBodyData()} for
     * the full contract.
     *
     * @return string|null the shared body class name, or null when the schema cannot type a body
     */
    public function generateComponentMultipartBodyData(string $componentName, string $operationLabel, SchemaNode|ReferenceNode $schema, ?string $tag = null): ?string
    {
        return $this->bodies->generateComponentMultipartBodyData($componentName, $operationLabel, $schema, $tag);
    }

    /**
     * Emit (or reuse) the SHARED Data class of a component response whose
     * JSON schema is an inline object (issue #116). Must be called AFTER
     * generate(); see {@see RequestDataSynthesizer::generateComponentResponseData()}
     * for the full contract.
     *
     * @return string|null the shared response class name, or null when the schema cannot type a response
     */
    public function generateComponentResponseData(string $componentName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        return $this->bodies->generateComponentResponseData($componentName, $operationLabel, $schema, $tag);
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
        $files = $this->state->queryFiles;
        ksort($files);

        return $files;
    }

    /**
     * The per-operation path Data classes emitted since the last generate()
     * run (issue #113), keyed and ordered by class name. Exposed as a getter,
     * mirroring queryFiles(): generate() already returned its file set when
     * the server scaffold asks for these.
     *
     * @return array<string, GeneratedFile>
     */
    public function pathFiles(): array
    {
        $files = $this->state->pathFiles;
        ksort($files);

        return $files;
    }

    /**
     * The per-operation header Data classes emitted since the last generate()
     * run (issue #121), keyed and ordered by class name. Exposed as a getter,
     * mirroring pathFiles(): generate() already returned its file set when
     * the server scaffold asks for these.
     *
     * @return array<string, GeneratedFile>
     */
    public function headerFiles(): array
    {
        $files = $this->state->headerFiles;
        ksort($files);

        return $files;
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
        $files = $this->state->bodyFiles;
        ksort($files);

        return $files;
    }

    /**
     * The shared component-response Data classes emitted since the last
     * generate() run (issue #116), keyed and ordered by class name. A
     * dedicated bucket mirroring bodyFiles() rather than an overload of it,
     * so the request and response synthesis stay auditable as distinct
     * surfaces; the planner collects both into the same data output.
     *
     * @return array<string, GeneratedFile>
     */
    public function responseFiles(): array
    {
        $files = $this->state->responseFiles;
        ksort($files);

        return $files;
    }

    /**
     * @return array<string, SchemaNode>
     */
    private function componentSchemas(OpenApiDocument $document): array
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
            // (issue #67). A mistyped (non-object) component entry never reaches
            // this map: the reader drops it during hydration.
            if ($schema instanceof ReferenceNode) {
                $this->state->warnings[sprintf(
                    'Component schema "%s" is a bare $ref ("%s") and is not generated; references to it degrade to mixed with presence-only validation.',
                    (string) $name,
                    $schema->pointer(),
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
    private function emitData(string $className, SchemaNode $schema, int $depth, string $variant = 'all', array $docIntro = []): void
    {
        $this->state->pushRefScope($className);

        $properties = $this->objectProperties($schema);
        $required = $this->requiredNames($schema);

        // A scalar component (no named properties) that is an enum PHP cannot
        // back as a native enum (a float enum) must still carry its constraint:
        // wrap it in a single `value` property typed by the scalar with the
        // `Rule::in` membership rule. Without this it would emit an empty,
        // useless Data class that silently accepts any value.
        if ($properties === [] && SchemaFacts::isScalarEnumComponent($schema)) {
            $properties = ['value' => $schema];
            $required = ['value'];
        }

        $base = $this->options->stripSuffix($className);
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
            if ($variant === 'read' && SchemaFacts::isWriteOnly($propertySchema)) {
                continue;
            }
            if ($variant === 'write' && SchemaFacts::isReadOnly($propertySchema)) {
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
            $filePart = $this->state->multipartBody && $depth === 0 ? $this->bodies->multipartFilePart($propertySchema) : null;

            $type = $filePart !== null
                ? $this->bodies->multipartFileType($filePart)
                : $this->types->resolveType($propertySchema, $base.PhpIdentifier::toClassName($wireName), $depth + 1, $variant);
            $this->state->noteClassRef(...$type->classRefs);

            // A scalar `default` makes the property optional on input even when
            // the spec lists it as required: an omitted value is filled by the
            // default, so the input rule is `sometimes`, not `required`. The
            // default also seeds the constructor parameter (`= 5`) instead of the
            // hardcoded `= null`, and a property carrying a default never sits in
            // the required-parameter group (PHP defaulted params must come last).
            $default = $this->renderer->defaultValue($propertySchema, $type);
            $isRequired = $listedRequired && $default === null;

            $rendered = $this->renderer->renderProperty($wireName, $propertyName, $type, $isRequired, $default, SchemaFacts::deprecationTag($propertySchema));

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            // Validation rules are keyed by the wire (mapped input) name. The
            // wildcard map carries one entry per array-nesting level ('.*',
            // '.*.*', ...) so a nested array enforces its inner item rules too.
            [$propertyRules, $wildcardRules, $uses] = $filePart !== null
                ? $this->bodies->multipartFileRules($filePart, $isRequired, $type)
                : $this->rules->buildRules($propertySchema, $isRequired, $type);
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
        $this->rules->applyDependentRequired($base, $schema, $required, $properties, $declaredNullable, $rules);

        // Closed-object enforcement (issue #30). When enforceClosedObjects is on
        // (the default) and the schema declares additionalProperties: false,
        // emit a payload-wide rule that rejects any key outside the declared
        // set. The sentinel key never collides with a real wire name, and the
        // rule is implicit so it fires once even when absent. Opting out with
        // --no-enforce-closed-objects restores the lenient output. A schema
        // that also declares patternProperties widens the allow-set with its
        // patterns (issue #65); see closedObjectRule().
        if ($this->options->enforceClosedObjects && SchemaFacts::declaresClosedObject($schema)) {
            $closedObjectRule = $this->rules->closedObjectRule($base, $declaredWireNames, $schema);
            if ($closedObjectRule !== null) {
                $rules[self::CLOSED_OBJECT_SENTINEL] = [$closedObjectRule];
            }
        }

        $params = array_merge($paramsRequired, $paramsOptional);
        $imports = $this->renderer->collectImports($params, $usesRule, $rules);

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
            $this->state->warnings[sprintf(
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
        $deprecationTag = SchemaFacts::deprecationTag($schema);
        if ($deprecationTag !== null) {
            $classDoc[] = $deprecationTag;
        }
        if ($this->notEmptyArray($properties) && SchemaFacts::additionalPropertiesSchema($schema) !== null) {
            $classDoc[] = 'This schema also declares additionalProperties: dynamic keys beyond the named properties above are not captured by this class.';
        }

        // Close this class's reference scope and import every cross-group
        // reference (issue #93); a flat-layout run records groups of null
        // everywhere, so the imports come back unchanged.
        $imports = $this->state->withCrossGroupImports($className, $imports, $this->state->popRefScope());

        $this->state->files[$className] = new GeneratedFile(
            $className,
            $this->renderer->renderDataClass($className, $params, $imports, $rules, $classDoc),
            $this->state->fileGroups[$className] ?? null,
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
        $this->state->pushRefScope($className);

        $wireName = (string) $this->state->discriminators->propertyName($baseName);
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
            // The morph() default arm throws this so an unmapped discriminator
            // value is a clean 422 (#124), not the uncatchable
            // CannotCreateAbstractClass the morph-then-validate creation path
            // (from() / validateAndCreate() / container injection) would
            // otherwise raise BEFORE validation ever runs.
            'Illuminate\\Validation\\ValidationException',
        ], $validationImports);

        $mapName = '';
        if (PhpIdentifier::needsMapName($wireName, $propertyName)) {
            $mapName = "        #[MapName('".PhpLiteral::escapeSingleQuoted($wireName)."')]\n";
            $imports[] = 'Spatie\\LaravelData\\Attributes\\MapName';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        // match() arm per discriminator value -> variant Data class, sorted by
        // value for determinism. The arm literal is typed by the discriminator:
        // for an int discriminator the payload value arrives as an int, and
        // match is strict, so the arm must be an int literal (1 not '1') or it
        // would never compare equal.
        //
        // The default arm THROWS a ValidationException rather than returning
        // null (#124). spatie's validate() path runs the morph guard
        // (EnsurePropertyMorphable) and would reject a null morph cleanly, but
        // the creation paths (from() / validateAndCreate() / container
        // injection) resolve the morph class BEFORE validation runs: a null
        // there raises CannotCreateAbstractClass, an uncatchable 500 for what is
        // really spec-invalid input. Throwing here makes every consumption path
        // surface a 422 for an unknown discriminator value. The message names
        // the discriminator wire key so the error bag matches the property.
        $arms = [];
        foreach ($this->state->discriminators->valueToVariant($baseName) as $value => $variantSchemaName) {
            $variantClass = $this->state->registry[$variantSchemaName]['class'] ?? null;
            if ($variantClass === null) {
                continue;
            }
            $this->state->noteClassRef($variantClass);
            $arms[] = '            '.$this->discriminatorValueLiteral((string) $value, $type).' => '.$variantClass.'::class,';
        }
        $arms[] = '            default => throw ValidationException::withMessages([';
        $arms[] = "                '".PhpLiteral::escapeSingleQuoted($wireName)."' => 'The selected ".PhpLiteral::escapeSingleQuoted($wireName)." is invalid.',";
        $arms[] = '            ]),';

        // A deprecated discriminated base carries a class-level `@deprecated`.
        $baseSchema = $this->state->registry[$baseName]['schema'] ?? null;
        $deprecationTag = $baseSchema instanceof SchemaNode ? SchemaFacts::deprecationTag($baseSchema) : null;

        // A variant may live in another tag group (issue #93): the morph()
        // arms reference it by short name, so import it from its real group.
        $imports = $this->state->withCrossGroupImports($className, $imports, $this->state->popRefScope());

        $this->state->files[$className] = new GeneratedFile(
            $className,
            $this->renderer->renderDiscriminatorBase($className, $imports, $mapName, $propertyName, $type, $validationAttributes, $arms, $deprecationTag),
            $this->state->fileGroups[$className] ?? null,
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
            return PhpLiteral::numberLiteral((int) $value);
        }

        return PhpLiteral::scalarLiteral($value);
    }

    /**
     * Emit a variant of a discriminated union. It extends the abstract base,
     * forwards the discriminator to the parent constructor (a non-promoted param
     * so the base owns the single declared discriminator property), and declares
     * its OWN remaining properties as promoted readonly params with their rules.
     */
    private function emitVariant(string $variantName, string $className, SchemaNode $schema): void
    {
        $this->state->pushRefScope($className);

        $baseName = (string) $this->state->discriminators->baseOf($variantName);
        $baseClass = $this->state->registry[$baseName]['class'] ?? 'Data';
        // The `extends` references the base by short name; under the grouped
        // layout (issue #93) the base may live in another group, so record the
        // reference (the defensive 'Data' fallback is never a generated class
        // and is filtered by withCrossGroupImports).
        $this->state->noteClassRef($baseClass);
        $discriminatorWire = (string) $this->state->discriminators->propertyName($baseName);
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
        $base = $this->options->stripSuffix($className);
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
            $type = $this->types->resolveType($propertySchema, $base.PhpIdentifier::toClassName($wireName), 1);
            $this->state->noteClassRef(...$type->classRefs);

            $default = $this->renderer->defaultValue($propertySchema, $type);
            $isRequired = $listedRequired && $default === null;

            $rendered = $this->renderer->renderProperty($wireName, $propertyName, $type, $isRequired, $default, SchemaFacts::deprecationTag($propertySchema));

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            [$propertyRules, $wildcardRules, $uses] = $this->rules->buildRules($propertySchema, $isRequired, $type);
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
        $this->rules->applyDependentRequired($base, $schema, $required, $properties, $declaredNullable, $rules);

        $params = array_merge($paramsRequired, $paramsOptional);

        // The variant extends the base, so it no longer needs the Spatie Data
        // import. The base lives in the same namespace as the variant, so it is
        // referenced by short name with no `use` (a same-namespace import would
        // be redundant and Pint would strip it).
        $imports = $this->renderer->collectImports($params, $usesRule, $rules);
        $imports = array_values(array_filter($imports, static fn (string $i): bool => $i !== 'Spatie\\LaravelData\\Data'));
        sort($imports);

        // Under the grouped layout (issue #93) the base or a referenced class
        // may live in another group; import those from their real namespaces.
        $imports = $this->state->withCrossGroupImports($className, $imports, $this->state->popRefScope());

        $this->state->files[$className] = new GeneratedFile(
            $className,
            $this->renderer->renderVariantClass($className, $baseClass, $discriminatorProperty, $discriminatorDeclaration, $params, $imports, $rules, SchemaFacts::deprecationTag($schema)),
            $this->state->fileGroups[$className] ?? null,
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
        $wireName = (string) $this->state->discriminators->propertyName($baseName);
        $schema = $this->discriminatorPropertySchema($baseName, $wireName);

        if ($schema === null) {
            return new ResolvedType('string');
        }

        return $this->types->resolveType($schema, $this->options->stripSuffix($className).PhpIdentifier::toClassName($wireName), 1);
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
    private function discriminatorConstRule(SchemaNode|ReferenceNode $schema): ?array
    {
        if (! $schema instanceof SchemaNode) {
            return null;
        }

        $const = SchemaFacts::constValue($schema);
        if ($const !== null) {
            return ['Rule::in(['.PhpLiteral::scalarLiteral($const[0]).'])'];
        }

        // A single-value enum pins the value just like a const.
        $values = SchemaFacts::enumValues($schema);
        if (count($values) === 1) {
            return ['Rule::in(['.PhpLiteral::scalarLiteral($values[0]).'])'];
        }

        return null;
    }

    /**
     * The schema of the discriminator property, taken from the first variant
     * (sorted) that declares it. All variants share the discriminator, so any
     * one is representative. Returns null when no variant types it.
     */
    private function discriminatorPropertySchema(string $baseName, string $wireName): ?SchemaNode
    {
        foreach ($this->state->discriminators->variants($baseName) as $variantSchemaName) {
            $schema = $this->state->registry[$variantSchemaName]['schema'] ?? null;
            if ($schema === null) {
                continue;
            }
            $property = $this->objectProperties($schema)[$wireName] ?? null;
            if ($property instanceof SchemaNode) {
                return $property;
            }
        }

        return null;
    }

    /**
     * The rules() key under which the opt-in closed-object rule is attached. It
     * is namespaced with a leading marker so it can never collide with a real
     * wire (input) name (OpenAPI property names do not begin with this prefix in
     * practice, and even if one did, the implicit rule keyed here is harmless).
     */
    private const CLOSED_OBJECT_SENTINEL = '__openapi_laravel_no_unknown_properties';

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
        $this->state->usedSupportClasses[$shortName] = true;
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
        foreach (array_keys($this->state->usedSupportClasses) as $shortName) {
            $files[$shortName] = $emitter->emit($shortName);
        }

        ksort($files);

        return $files;
    }

    /**
     * The namespace a generated class is emitted into: the configured Data
     * namespace, extended by the class's tag group under the grouped layout
     * (issue #93). Public so the server scaffold imports Data classes from
     * the namespace they actually land in.
     */
    public function namespaceFor(string $className): string
    {
        return $this->state->namespaceFor($className);
    }

    private function hasReadWriteFlags(SchemaNode $schema): bool
    {
        foreach ($this->objectProperties($schema) as $property) {
            if (SchemaFacts::isReadOnly($property) || SchemaFacts::isWriteOnly($property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a diagnostic when a property schema carries a non-standard
     * per-property `required` key (a boolean `required` set on the property
     * object itself). OpenAPI 3.x ignores this: a property is required only when
     * the OWNING schema's `required: [...]` array lists it. SchemaNode types
     * `required` as `list<string>|bool|null` exactly so this real-world misuse
     * survives hydration (issue #104) and can be warned about here.
     *
     * The detection is intentionally narrow: only a boolean value triggers it,
     * so a legitimate schema-level `required` array nested inside a property
     * (itself an object schema) is never mistaken for the non-standard key.
     */
    private function warnPerPropertyRequired(string $schemaName, string $propertyName, SchemaNode|ReferenceNode $propertySchema): void
    {
        if (! $propertySchema instanceof SchemaNode) {
            return;
        }

        if (! is_bool($propertySchema->required)) {
            return;
        }

        $this->state->warnings[sprintf(
            'Property "%s" on schema "%s" has a non-standard per-property "required" key, which OpenAPI ignores. '
            .'Use the schema-level "required" array instead. This field is generated as optional.',
            $propertyName,
            $schemaName,
        )] = true;
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
     * @param  array<string, SchemaNode>  $schemas
     */
    private function promoteChainedAliases(array $schemas): void
    {
        do {
            $promoted = false;

            foreach ($schemas as $name => $schema) {
                if (isset($this->state->aliasSchemas[$name]) || ! isset($this->state->registry[$name])) {
                    continue;
                }

                $ref = SchemaFacts::bareAllOfRef($schema);
                if ($ref === null) {
                    continue;
                }

                $target = SchemaPointer::refName($ref->pointer());
                if ($target === null || ! isset($this->state->aliasSchemas[$target])) {
                    continue;
                }

                // The target is an alias: this thin wrapper is an alias too.
                unset($this->state->registry[$name], $this->state->files[$name]);
                $this->state->aliasSchemas[$name] = $schema;
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
    private function isNonObjectAlias(SchemaNode $schema): bool
    {
        // A component with named properties is an object Data class, not an alias.
        if ($this->notEmptyArray($schema->properties)) {
            return false;
        }

        // Pure-map and enum components have their own handling; never alias them.
        if (SchemaFacts::isPureMap($schema) || SchemaFacts::isEnum($schema) || SchemaFacts::isScalarEnumComponent($schema)) {
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

        $types = SchemaFacts::normalizeTypes($schema);
        $primary = $types[0] ?? null;

        // A scalar (string/int/float/bool) or array component is a non-object
        // alias. A multi-type scalar array (`type: ["string","integer"]`) is a
        // scalar union and also aliases. `object` (or untyped/empty, which is a
        // legitimately empty object after the pure-map check above) is NOT.
        if ($primary === 'array') {
            return true;
        }

        foreach ($types as $type) {
            if (! isset(SchemaFacts::SCALARS[$type])) {
                return false;
            }
        }

        return $types !== [];
    }

    /**
     * The property map for a schema, with any `allOf` members merged in.
     *
     * @return array<string, SchemaNode|ReferenceNode>
     */
    private function objectProperties(SchemaNode $schema): array
    {
        return $this->mergeAllOf($schema)['properties'];
    }

    /**
     * The required-property names for a schema, with any `allOf` members merged in.
     *
     * @return list<string>
     */
    private function requiredNames(SchemaNode $schema): array
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
     * @return array{properties: array<string, SchemaNode|ReferenceNode>, required: list<string>}
     */
    private function mergeAllOf(SchemaNode $schema, array $seen = []): array
    {
        $ownProperties = SchemaFacts::localProperties($schema);
        $ownRequired = SchemaFacts::localRequired($schema);

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
            $resolved = $this->state->resolveMemberSchema($member, $seen);
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
    private function warnUnmergedAllOfMember(SchemaNode|ReferenceNode $member, array $seen): void
    {
        if (! $member instanceof ReferenceNode) {
            return;
        }

        $pointer = $member->pointer();
        $name = SchemaPointer::refName($pointer);

        if ($name === null) {
            $this->state->warnings[sprintf(
                '%s: allOf member $ref "%s" is external or not a #/components/schemas pointer; its properties are not merged into the composed class. Bundle external references into one document before generating.',
                $this->state->warningContext,
                $pointer,
            )] = true;

            return;
        }

        if (isset($seen[$name]) || isset($this->state->aliasSchemas[$name]) || isset($this->state->mapSchemas[$name])) {
            return;
        }

        $this->state->warnings[sprintf(
            '%s: allOf member $ref "%s" does not resolve to a component schema; its properties are not merged into the composed class.',
            $this->state->warningContext,
            $pointer,
        )] = true;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function dedupe(array $names): array
    {
        return array_values(array_unique($names));
    }

    private function notEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
