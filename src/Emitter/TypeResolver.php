<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use Closure;
use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Resolves a schema (or `$ref`) to the PHP type it generates (issue #109,
 * extracted from ModelGenerator).
 *
 * Scalars map directly, `oneOf`/`anyOf` become native unions when every member
 * is clean, arrays carry their item type, pure maps inline as typed arrays,
 * non-object aliases chase through to their underlying type, and inline object
 * schemas spawn nested Data classes through the injected emit callback (the
 * recursion back into ModelGenerator::emitData()). Lookups go through the
 * shared {@see GenerationState}.
 *
 * @internal
 */
final class TypeResolver
{
    /**
     * @param  Closure(string, SchemaNode, int, string): void  $emitData  spawns a nested Data class mid-resolution
     */
    public function __construct(
        private readonly GenerationState $state,
        private readonly Closure $emitData,
    ) {}

    /**
     * Cycle guard for the alias being resolved, so a chained alias $ref reached
     * through resolveReference does not recurse forever on a cyclic chain.
     *
     * @var array<string, true>
     */
    private array $aliasSeen = [];

    public function resolveType(SchemaNode|ReferenceNode $schema, string $nameHint, int $depth, string $variant = 'all'): ResolvedType
    {
        if ($depth > $this->state->options->maxDepth) {
            throw new GenerationException("Maximum schema depth ({$this->state->options->maxDepth}) exceeded at {$nameHint}.");
        }

        if ($schema instanceof ReferenceNode) {
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

        $types = SchemaFacts::normalizeTypes($schema);
        $nullable = SchemaFacts::isNullable($schema);
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

        if ($primary !== null && isset(SchemaFacts::SCALARS[$primary])) {
            return new ResolvedType(SchemaFacts::SCALARS[$primary], $nullable);
        }

        // A bare `const` with no declared type: infer the PHP type from the
        // const literal so the property is typed (string or int) rather than
        // mixed. A typed const already took the scalar path above.
        if ($primary === null) {
            $const = SchemaFacts::constValue($schema);
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
        if (SchemaFacts::isPureMap($schema)) {
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
        $aliasRef = SchemaFacts::bareAllOfRef($schema);
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
    private function resolveUnion(SchemaNode $schema, string $nameHint, int $depth, string $variant): ResolvedType
    {
        $members = SchemaPointer::unionMembers($schema);

        // A `oneOf`/`anyOf` with no usable members carries no shape: mixed.
        if ($members === []) {
            return new ResolvedType('mixed', SchemaFacts::isNullable($schema));
        }

        // Pre-scan every member for the `null` type up front, not incrementally,
        // so a union whose `type: null` member sits AFTER a messy member still
        // resolves as nullable. Without this, an early messy-member fallback (a
        // `type: object` member before the `null` member) returned a non-nullable
        // `mixed`, and a required+nullable oneOf then emitted a bare `required`
        // rule that false-rejected a spec-valid present null (issue #8).
        $nullable = SchemaFacts::isNullable($schema);
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
                $this->state->warnings[$member instanceof ReferenceNode
                    ? sprintf(
                        '%s: a oneOf/anyOf member $ref "%s" does not resolve to a plain scalar or a generated Data class; the union degrades to mixed with presence-only validation.',
                        $this->state->warningContext,
                        $member->pointer(),
                    )
                    : sprintf(
                        '%s: a oneOf/anyOf member is not a plain scalar or a $ref to a generated Data class; the union degrades to mixed with presence-only validation.',
                        $this->state->warningContext,
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
            if (! isset(SchemaFacts::SCALARS[$type])) {
                return null;
            }

            $declaration = SchemaFacts::SCALARS[$type];
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
     * Whether a union member is the bare `null` type (`{type: 'null'}` or a type
     * array of only null), which contributes nullability rather than a PHP type.
     */
    private function isNullTypeMember(SchemaNode|ReferenceNode $member): bool
    {
        if (! $member instanceof SchemaNode) {
            return false;
        }

        $raw = $member->type;

        if ($raw === 'null') {
            return true;
        }

        return is_array($raw) && $raw !== [] && SchemaFacts::normalizeTypes($member) === [];
    }

    /**
     * Whether a union member resolves to a clean PHP type: a scalar, or a $ref to
     * a generated Data class. Anything else (a nested union, an array, a map, an
     * inline object, an untyped/empty schema, an enum-only schema, or an
     * unresolvable/pure-map $ref) is rejected so the whole union falls back to
     * mixed. Keeping the accepted set small is deliberate: the union type hint is
     * only emitted when it is unambiguously correct.
     */
    private function isCleanUnionMember(SchemaNode|ReferenceNode $member): bool
    {
        if ($member instanceof ReferenceNode) {
            $name = SchemaPointer::refName($member->pointer());
            if ($name === null) {
                return false;
            }

            // A $ref to a generated Data class is clean. A pure-map alias (typed
            // array) or an unknown/enum ref is not part of an object union.
            if (isset($this->state->registry[$name]) && $this->state->registry[$name]['kind'] === 'data') {
                return true;
            }

            // A $ref to a non-object alias is clean only when it resolves to a
            // scalar (string|int): an array/map/union/mixed alias is not a clean
            // single union member. The resolved alias type drives the decision.
            if (isset($this->state->aliasSchemas[$name])) {
                $resolved = $this->resolveAlias($name, $this->aliasSeen);

                return ! $resolved->isUnion
                    && ! $resolved->isMap
                    && in_array($resolved->declaration, SchemaFacts::SCALARS, true);
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

        $types = SchemaFacts::normalizeTypes($member);

        // Exactly one declared scalar type is clean. Zero types (untyped/empty),
        // multiple types, 'array', or 'object' are not.
        if (count($types) !== 1) {
            return false;
        }

        return isset(SchemaFacts::SCALARS[$types[0]]);
    }

    /**
     * Resolve a `$ref` to the type it generates. The two degradation paths (an
     * external or non-schema pointer, and a pointer to a component that did not
     * become a generated type) both produce `mixed` with presence-only rules,
     * so each emits a build warning naming the pointer and the schema (or
     * operation) where it was encountered (issue #67) instead of hollowing the
     * output silently.
     */
    private function resolveReference(ReferenceNode $reference): ResolvedType
    {
        $pointer = $reference->pointer();
        $name = SchemaPointer::refName($pointer);

        if ($name === null) {
            $this->state->warnings[sprintf(
                '%s: $ref "%s" is external or not a #/components/schemas pointer and degrades to mixed with presence-only validation. Bundle external references into one document before generating.',
                $this->state->warningContext,
                $pointer,
            )] = true;

            return new ResolvedType('mixed');
        }

        // A reference to a pure-map component inlines the array type at the use
        // site (the component itself has no Data class). The cached type was
        // resolved during classification (outside any emission scope), so its
        // recorded class references are replayed into the current scope here.
        if (isset($this->state->mapAliases[$name])) {
            $this->state->noteClassRef(...$this->state->mapAliases[$name]->classRefs);

            return $this->state->mapAliases[$name];
        }

        // A reference to a non-object alias component (scalar/array/union)
        // resolves to its underlying type at the use site. Resolution is
        // transitive (alias -> alias) and cycle-guarded via $aliasSeen, so a
        // chain reached mid-resolution still terminates. Cached like the map
        // aliases, so its class references are replayed too.
        if (isset($this->state->aliasSchemas[$name])) {
            $resolved = $this->resolveAlias($name, $this->aliasSeen);
            $this->state->noteClassRef(...$resolved->classRefs);

            return $resolved;
        }

        if (isset($this->state->registry[$name]) && is_string($this->state->registry[$name]['class'])) {
            // A reference to a generated backed enum is a native PHP enum, not a
            // Data class: mark it so an array of enums is not given an invalid
            // #[DataCollectionOf(SomeEnum::class)] attribute (which targets
            // class-string<BaseData>, failing PHPStan). spatie hydrates the backed
            // enum from the typed array via the array<int, SomeEnum> docblock.
            $isEnum = $this->state->registry[$name]['kind'] === 'enum';
            $class = $this->state->registry[$name]['class'];
            $this->state->noteClassRef($class);

            return new ResolvedType($class, isEnum: $isEnum, classRefs: [$class]);
        }

        $this->state->warnings[sprintf(
            '%s: $ref "%s" does not resolve to a generated type and degrades to mixed with presence-only validation.',
            $this->state->warningContext,
            $pointer,
        )] = true;

        return new ResolvedType('mixed');
    }

    private function resolveArray(SchemaNode $schema, string $nameHint, int $depth, bool $nullable, string $variant = 'all'): ResolvedType
    {
        $items = $schema->items;

        if (! $items instanceof SchemaNode && ! $items instanceof ReferenceNode) {
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

    private function resolveInlineObject(SchemaNode $schema, string $nameHint, int $depth, bool $nullable, string $variant = 'all'): ResolvedType
    {
        $className = $this->state->names->reserve($this->state->options->withSuffix($nameHint));

        // A nested inline class follows the class that spawned it (issue #93),
        // so a holder's tag group propagates to its whole inline subtree.
        $this->state->fileGroups[$className] = $this->state->currentScopeGroup();

        // Reserve the slot before recursing so nested cycles cannot reuse it.
        $this->state->files[$className] = new GeneratedFile($className, '');
        ($this->emitData)($className, $schema, $depth, $variant);

        return new ResolvedType($className, $nullable, classRefs: [$className]);
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
    public function resolveAlias(string $name, array $seen = []): ResolvedType
    {
        if (isset($this->state->aliasTypes[$name])) {
            return $this->state->aliasTypes[$name];
        }

        if (isset($seen[$name])) {
            $this->state->warnings[sprintf(
                'Component schema "%s" is part of a cyclic alias chain and degrades to mixed with presence-only validation.',
                $name,
            )] = true;

            return new ResolvedType('mixed');
        }

        $schema = $this->state->aliasSchemas[$name] ?? null;
        if ($schema === null) {
            return new ResolvedType('mixed');
        }

        $seen[$name] = true;
        $this->aliasSeen = $seen;

        // Aliases resolve lazily, possibly mid-emission of another schema, so
        // the warning context is restored afterwards: a degradation inside the
        // alias is attributed to the alias component, not to whichever use
        // site happened to trigger the resolution first.
        $previousContext = $this->state->warningContext;
        $this->state->warningContext = sprintf('Schema "%s"', $name);

        // A nested class spawned from inside the alias (an array-of-inline-
        // object alias) follows the alias component's own tag group (issue
        // #93); the refs recorded here are discarded, because use sites replay
        // them from the cached classRefs.
        $this->state->pushGroupScope($this->state->groupForSchema($name));

        try {
            $resolved = $this->resolveType($schema, PhpIdentifier::toClassName($name), 0);
        } finally {
            $this->state->popRefScope();
            $this->state->warningContext = $previousContext;
            $this->aliasSeen = [];
        }

        return $this->state->aliasTypes[$name] = $resolved;
    }

    /**
     * Resolve a pure-map schema to its array type: `array<string, X>` where X is
     * derived from the `additionalProperties` value schema. Scalar values map to
     * their PHP type, a `$ref` value maps to the referenced Data class, and
     * `true`/untyped maps to `mixed`.
     */
    public function mapType(SchemaNode $schema, string $nameHint, int $depth, string $variant): ResolvedType
    {
        $value = SchemaFacts::additionalPropertiesSchema($schema);
        $nullable = SchemaFacts::isNullable($schema);

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
     * Whether a merged schema is nullable: the composing schema or any allOf
     * member (recursively) declares nullability. A single nullable member is
     * enough, matching how `allOf` constrains the combined value.
     *
     * @param  array<string, true>  $seen  component names already visited (keyed for O(1) cycle checks)
     */
    private function mergedNullable(SchemaNode $schema, array $seen = []): bool
    {
        if (SchemaFacts::isNullable($schema)) {
            return true;
        }

        $members = $schema->allOf;
        if (! is_array($members)) {
            return false;
        }

        foreach ($members as $member) {
            $resolved = $this->state->resolveMemberSchema($member, $seen);
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

    private function isDataClass(ResolvedType $type): bool
    {
        return $type->declaration !== 'mixed'
            && $type->declaration !== 'array'
            && ! in_array($type->declaration, SchemaFacts::SCALARS, true);
    }

    private function notEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
