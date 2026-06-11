<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;

/**
 * Pre-pass over the component schemas that finds discriminated object unions and
 * records, for each one, how it morphs at runtime.
 *
 * Three shapes are recognised, all producing the same runtime treatment (an
 * abstract morphable base plus one variant Data class per member, validated and
 * hydrated by spatie's PropertyMorphableData):
 *
 *   1. NAMED-COMPONENT form: a `oneOf`/`anyOf` of `$ref`s plus a `discriminator`.
 *      Each member is a named component; the base is the union component itself.
 *
 *        Pet:
 *          oneOf: [{$ref: Cat}, {$ref: Dog}]
 *          discriminator: {propertyName: petType, mapping: {cat: Cat, dog: Dog}}
 *
 *   2. INLINE-UNION form: a `oneOf`/`anyOf` plus a `discriminator` whose members
 *      are INLINE object schemas (not `$ref`s). The variants have no component
 *      name, so a deterministic, collision-safe name is SYNTHESIZED per member
 *      (derived from the discriminator mapping value, else the member index).
 *      The synthesized variant schemas are held here and emitted by the generator.
 *
 *        Shape:
 *          oneOf:
 *            - {type: object, properties: {kind: {const: a}, ...}}
 *            - {type: object, properties: {kind: {const: b}, ...}}
 *          discriminator: {propertyName: kind, mapping: {a: '#a', b: '#b'}}
 *
 *   3. allOf-INHERITANCE form: a base OBJECT component that declares the
 *      `discriminator` (and the discriminator property) directly, with no
 *      oneOf/anyOf. Each variant is a named component composed as
 *      `allOf: [ {$ref: Base}, { ...own props } ]`. The `discriminator.mapping`
 *      (or the implicit component-name mapping) maps values to those variants.
 *
 *        Pet:
 *          type: object
 *          properties: {petType: {type: string}}
 *          discriminator: {propertyName: petType, mapping: {cat: Cat, dog: Dog}}
 *        Cat:
 *          allOf: [{$ref: Pet}, {type: object, properties: {meow: ...}}]
 *
 * For every form the discriminator value of a variant is its `mapping` entry when
 * present, otherwise (per the OpenAPI spec) the variant's component schema name
 * (for forms 1 and 3) or the synthesized name (form 2). Every variant must resolve
 * to an OBJECT schema; a scalar/array/non-object member degrades the whole union
 * to the existing presence-only (`mixed`) behavior with a build warning.
 *
 * A variant referenced by more than one discriminated base keeps the FIRST base
 * (PHP has single inheritance); later bases that would claim it are skipped, and
 * those bases fall back to the existing behavior. This keeps the registry a clean
 * tree and never crashes.
 *
 * The registry is built once and consulted by ModelGenerator. It is a lookup
 * table plus a small side table of SYNTHESIZED variant schemas (form 2): it emits
 * nothing and holds no PHP identifiers (the generator owns naming), only raw
 * schema names. Ordering is deterministic: component schemas are scanned in
 * sorted order.
 */
final class DiscriminatorRegistry
{
    /**
     * Base schema name => discriminator details.
     *
     * @var array<string, array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>, kind: 'named'|'inline'|'allof'}>
     */
    private array $bases = [];

    /**
     * Variant schema name => its base schema name. A variant can map to only one
     * base (PHP single inheritance); the first base seen wins.
     *
     * @var array<string, string>
     */
    private array $variantToBase = [];

    /**
     * Synthesized variant schemas for the inline-union form (form 2), keyed by the
     * synthesized variant name. These names are NOT real component schemas, so the
     * generator pulls the schema from here when registering and emitting them.
     *
     * @var array<string, Schema>
     */
    private array $syntheticVariants = [];

    /**
     * For an allOf-inheritance variant (form 3), the base whose properties the
     * variant inherits and must NOT redeclare. Keyed by variant name. (Form 1 and
     * 2 variants are standalone, so they never appear here.)
     *
     * @var array<string, string>
     */
    private array $variantInheritsFrom = [];

    /**
     * Non-fatal diagnostics gathered while classifying discriminated unions,
     * keyed by message so the same finding is recorded once. Each entry explains
     * why a schema that looked like a discriminated union was NOT given full
     * morph handling (a non-object member, a multi-base conflict, or a base left
     * with no claimable variants), so the silent degrade to presence-only is
     * visible. The generator merges these into its own warnings() channel.
     *
     * @var array<string, true>
     */
    private array $warnings = [];

    /**
     * @param  array<string, Schema>  $schemas  component schemas, name => schema
     */
    public function __construct(array $schemas)
    {
        ksort($schemas);

        // First, find the allOf-inheritance variants: a base that declares a
        // discriminator directly (no oneOf/anyOf) claims every component whose
        // allOf composes a $ref to it. Computed up front so analyze() can attach
        // the variant list to the base.
        $allOfVariants = $this->collectAllOfVariants($schemas);

        $candidates = [];
        foreach ($schemas as $name => $schema) {
            $candidate = $this->analyze($name, $schema, $schemas, $allOfVariants);
            if ($candidate !== null) {
                $candidates[$name] = $candidate;
            }
        }

        // Claim variants in sorted base order so the "first base wins" rule is
        // deterministic. A base whose every variant is already claimed by an
        // earlier base, or that collides on a variant, still keeps the variants
        // it can claim; a base left with zero claimable variants is dropped.
        foreach ($candidates as $baseName => $candidate) {
            $claimed = [];
            $stolen = [];
            foreach ($candidate['variants'] as $variant) {
                if (isset($this->variantToBase[$variant])) {
                    // Already owned by an earlier base: skip it for this base. PHP
                    // single inheritance means a variant can extend only one base.
                    $stolen[$variant] = $this->variantToBase[$variant];

                    continue;
                }
                $this->variantToBase[$variant] = $baseName;
                $claimed[] = $variant;
            }

            if ($stolen !== []) {
                foreach ($stolen as $variant => $owner) {
                    $this->warnings[sprintf(
                        'Discriminated union "%s" shares variant "%s" with union "%s"; PHP single inheritance keeps the variant under "%s", '
                        .'so "%s" cannot fully claim it.',
                        $baseName,
                        $variant,
                        $owner,
                        $owner,
                        $baseName,
                    )] = true;
                }
            }

            if ($claimed === []) {
                // Every variant was claimed by an earlier base: this union cannot
                // become its own base, so it degrades to presence-only behavior.
                $this->warnings[sprintf(
                    'Discriminated union "%s" has no variant left to claim (all shared with earlier unions); '
                    .'it is generated as a presence-only union, not a morphable base.',
                    $baseName,
                )] = true;

                continue;
            }

            $valueToVariant = [];
            foreach ($candidate['valueToVariant'] as $value => $variant) {
                if (in_array($variant, $claimed, true)) {
                    $valueToVariant[$value] = $variant;
                }
            }

            // Record synthesized variant schemas (inline form) and inheritance
            // links (allOf form) only for the variants this base actually claimed.
            foreach ($claimed as $variant) {
                if (isset($candidate['syntheticVariants'][$variant])) {
                    $this->syntheticVariants[$variant] = $candidate['syntheticVariants'][$variant];
                }
                if (isset($candidate['variantInheritsFrom'][$variant])) {
                    $this->variantInheritsFrom[$variant] = $candidate['variantInheritsFrom'][$variant];
                }
            }

            $this->bases[$baseName] = [
                'propertyName' => $candidate['propertyName'],
                'valueToVariant' => $valueToVariant,
                'variants' => $claimed,
                'kind' => $candidate['kind'],
            ];
        }
    }

    /**
     * The diagnostics gathered while classifying discriminated unions, sorted for
     * determinism. Empty when every discriminated union was cleanly handled.
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
     * Whether a component schema name is a discriminated union base.
     */
    public function isBase(string $schemaName): bool
    {
        return isset($this->bases[$schemaName]);
    }

    /**
     * Whether a component schema name is a variant of some discriminated base.
     */
    public function isVariant(string $schemaName): bool
    {
        return isset($this->variantToBase[$schemaName]);
    }

    /**
     * The base schema name a variant belongs to, or null when it is not a variant.
     */
    public function baseOf(string $variantSchemaName): ?string
    {
        return $this->variantToBase[$variantSchemaName] ?? null;
    }

    /**
     * The synthesized variant schemas (inline-union form), name => Schema, sorted
     * for determinism. These names are not real components; the generator registers
     * and emits them as variant Data classes. Empty when no inline union exists.
     *
     * @return array<string, Schema>
     */
    public function syntheticVariants(): array
    {
        $variants = $this->syntheticVariants;
        ksort($variants);

        return $variants;
    }

    /**
     * The base an allOf-inheritance variant inherits from (and whose properties it
     * must not redeclare), or null when the variant is standalone (form 1 or 2).
     */
    public function inheritedBaseOf(string $variantSchemaName): ?string
    {
        return $this->variantInheritsFrom[$variantSchemaName] ?? null;
    }

    /**
     * The discriminator property name (wire name) for a base, or null when the
     * schema is not a base.
     */
    public function propertyName(string $baseSchemaName): ?string
    {
        return $this->bases[$baseSchemaName]['propertyName'] ?? null;
    }

    /**
     * The discriminator-value => variant-schema-name map for a base, in
     * deterministic (sorted by value) order. Empty when the schema is not a base.
     *
     * @return array<string, string>
     */
    public function valueToVariant(string $baseSchemaName): array
    {
        $map = $this->bases[$baseSchemaName]['valueToVariant'] ?? [];
        ksort($map);

        return $map;
    }

    /**
     * The variant schema names of a base, in sorted order. Empty when not a base.
     *
     * @return list<string>
     */
    public function variants(string $baseSchemaName): array
    {
        return $this->bases[$baseSchemaName]['variants'] ?? [];
    }

    /**
     * Find every allOf-inheritance variant: a component whose `allOf` composes a
     * `$ref` to a base that itself declares a discriminator (no oneOf/anyOf). The
     * result maps base name => list of variant names that inherit from it. A base
     * with no such variant is not in the map. Deterministic (sorted).
     *
     * @param  array<string, Schema>  $schemas
     * @return array<string, list<string>>
     */
    private function collectAllOfVariants(array $schemas): array
    {
        $result = [];
        foreach ($schemas as $name => $schema) {
            if (! $this->notEmptyArray($schema->allOf)) {
                continue;
            }
            foreach ($schema->allOf as $member) {
                if (! $member instanceof Reference) {
                    continue;
                }
                $baseName = $this->refName($member->getReference());
                if ($baseName === null) {
                    continue;
                }
                $base = $schemas[$baseName] ?? null;
                if ($base === null || ! $this->isAllOfInheritanceBase($base)) {
                    continue;
                }
                $result[$baseName][] = (string) $name;
            }
        }

        foreach ($result as $baseName => $variants) {
            sort($variants);
            $result[$baseName] = $variants;
        }
        ksort($result);

        return $result;
    }

    /**
     * Whether a schema is an allOf-inheritance BASE: an object schema that
     * declares a discriminator directly and is NOT itself a oneOf/anyOf union (a
     * union with a discriminator is the named/inline form, classified elsewhere).
     */
    private function isAllOfInheritanceBase(Schema $schema): bool
    {
        if ($schema->discriminator === null) {
            return false;
        }
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return false;
        }

        return $this->isObjectSchema($schema);
    }

    /**
     * Classify one component schema as a discriminated-union candidate, or null
     * when it is not a supported shape. Does not yet apply the single-base claiming
     * rule (that runs over all candidates afterwards), so the returned `variants`
     * list is the full member set.
     *
     * @param  array<string, Schema>  $schemas
     * @param  array<string, list<string>>  $allOfVariants  base name => allOf-inheritance variant names
     * @return array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>, kind: 'named'|'inline'|'allof', syntheticVariants: array<string, Schema>, variantInheritsFrom: array<string, string>}|null
     */
    private function analyze(string $name, Schema $schema, array $schemas, array $allOfVariants): ?array
    {
        $discriminator = $schema->discriminator;
        if ($discriminator === null) {
            return null;
        }

        $propertyName = $discriminator->propertyName;
        if (! is_string($propertyName) || $propertyName === '') {
            return null;
        }

        $mapping = is_array($discriminator->mapping) ? $discriminator->mapping : [];

        // A oneOf/anyOf union with a discriminator is either the named-component
        // form (all members $ref) or the inline-union form (some members inline).
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return $this->analyzeUnion($name, $schema, $schemas, $mapping, $propertyName);
        }

        // Otherwise, an object base that declares the discriminator directly is the
        // allOf-inheritance form, with variants found by collectAllOfVariants.
        if ($this->isAllOfInheritanceBase($schema)) {
            return $this->analyzeAllOfInheritance($name, $schemas, $mapping, $propertyName, $allOfVariants[$name] ?? []);
        }

        return null;
    }

    /**
     * Classify a `oneOf`/`anyOf` + discriminator schema. When every member is a
     * `$ref` to an object component it is the named-component form; when any member
     * is an inline object schema it is the inline-union form and the inline members
     * get synthesized variant names + schemas.
     *
     * @param  array<string, Schema>  $schemas
     * @param  array<array-key, mixed>  $mapping  raw discriminator mapping
     * @return array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>, kind: 'named'|'inline'|'allof', syntheticVariants: array<string, Schema>, variantInheritsFrom: array<string, string>}|null
     */
    private function analyzeUnion(string $name, Schema $schema, array $schemas, array $mapping, string $propertyName): ?array
    {
        $members = $this->unionMembers($schema);
        if ($members === []) {
            return null;
        }

        // A member set that is entirely $refs to object components is the
        // established named-component form. A set with at least one inline member
        // is the inline-union form. A $ref pointing outside the components, or a
        // non-object inline member, degrades the whole union to presence-only.
        $allRefs = true;
        foreach ($members as $member) {
            if (! $member instanceof Reference) {
                $allRefs = false;

                break;
            }
        }

        if ($allRefs) {
            return $this->analyzeNamedUnion($name, $members, $schemas, $mapping, $propertyName);
        }

        return $this->analyzeInlineUnion($name, $members, $schemas, $mapping, $propertyName);
    }

    /**
     * The named-component form: a `oneOf`/`anyOf` whose members are all `$ref`s to
     * object components. Unchanged behavior from the original registry.
     *
     * @param  list<Schema|Reference>  $members
     * @param  array<string, Schema>  $schemas
     * @param  array<array-key, mixed>  $mapping
     * @return array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>, kind: 'named'|'inline'|'allof', syntheticVariants: array<string, Schema>, variantInheritsFrom: array<string, string>}|null
     */
    private function analyzeNamedUnion(string $name, array $members, array $schemas, array $mapping, string $propertyName): ?array
    {
        $memberNames = [];
        foreach ($members as $member) {
            if (! $member instanceof Reference) {
                return null;
            }
            $refName = $this->refName($member->getReference());
            if ($refName === null) {
                return null;
            }
            if (! in_array($refName, $memberNames, true)) {
                $memberNames[] = $refName;
            }
        }

        // Every member must resolve to an OBJECT schema, or this is not a clean
        // discriminated object union: warn and degrade to presence-only.
        foreach ($memberNames as $memberName) {
            if (! $this->isObjectSchema($schemas[$memberName] ?? null)) {
                $reason = isset($schemas[$memberName]) ? 'is not an object schema' : 'does not resolve to a component schema';
                $this->warnings[sprintf(
                    'Discriminated union "%s" has member "%s" that %s; the union is generated as a presence-only "mixed" '
                    .'property instead of a morphable base.',
                    $name,
                    $memberName,
                    $reason,
                )] = true;

                return null;
            }
        }

        $valueToVariant = $this->buildValueMap($memberNames, $mapping, $schemas);
        $variants = $valueToVariant['variants'];

        return [
            'propertyName' => $propertyName,
            'valueToVariant' => $valueToVariant['map'],
            'variants' => $variants,
            'kind' => 'named',
            'syntheticVariants' => [],
            'variantInheritsFrom' => [],
        ];
    }

    /**
     * The inline-union form: a `oneOf`/`anyOf` + discriminator with at least one
     * inline (non-$ref) member. Each member gets a synthesized, collision-safe
     * variant name derived from its discriminator value (the mapping key that
     * points at it, else the member's own `const`/single-enum discriminator value,
     * else the member index). The synthesized schema is held for the generator.
     *
     * A non-object member, or a member with no derivable discriminator value,
     * degrades the whole union to presence-only with a warning.
     *
     * @param  list<Schema|Reference>  $members
     * @param  array<string, Schema>  $schemas
     * @param  array<array-key, mixed>  $mapping
     * @return array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>, kind: 'named'|'inline'|'allof', syntheticVariants: array<string, Schema>, variantInheritsFrom: array<string, string>}|null
     */
    private function analyzeInlineUnion(string $name, array $members, array $schemas, array $mapping, string $propertyName): ?array
    {
        // Every member must be an inline object schema; collect them as a typed
        // list (a $ref mixed into the union, or a non-object inline member, makes
        // the whole union non-clean and degrades to presence-only).
        $inlineMembers = [];
        foreach ($members as $member) {
            if (! $member instanceof Schema) {
                $this->warnInlineDegrade($name, 'a member is a $ref mixed into an inline union');

                return null;
            }
            if (! $this->isObjectSchema($member)) {
                $this->warnInlineDegrade($name, 'an inline member is not an object schema');

                return null;
            }
            $inlineMembers[] = $member;
        }

        // Map a discriminator value to the inline member index it selects. When a
        // member pins its own discriminator value (const/enum) that is the default;
        // a mapping entry pointing at an inline member by index then wins, so an
        // explicit mapping always works even with no const pin.
        $valueOfIndex = [];
        foreach ($inlineMembers as $index => $member) {
            $pinned = $this->memberDiscriminatorValue($member, $propertyName);
            if ($pinned !== null) {
                $valueOfIndex[$index] = $pinned;
            }
        }

        foreach ($mapping as $value => $target) {
            if (! is_string($target)) {
                continue;
            }
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $index = $this->inlineMemberIndex($target, count($inlineMembers));
            if ($index !== null) {
                $valueOfIndex[$index] = $value;
            }
        }

        // Every member needs a discriminator value, or morph cannot route to it.
        foreach ($inlineMembers as $index => $_member) {
            if (! isset($valueOfIndex[$index])) {
                $this->warnInlineDegrade($name, 'an inline member has no discriminator value (no mapping entry and no const/enum pin)');

                return null;
            }
        }

        // Synthesize a deterministic, collision-safe variant name per member from
        // its discriminator value, and build the value map over those names.
        $valueToVariant = [];
        $variants = [];
        $synthetic = [];
        $usedNames = $schemas;
        foreach ($inlineMembers as $index => $member) {
            $value = $valueOfIndex[$index];
            $variantName = $this->synthesizeVariantName($name, $value, $usedNames);
            $usedNames[$variantName] = $member;
            $synthetic[$variantName] = $member;
            $variants[] = $variantName;
            $valueToVariant[$value] = $variantName;
        }

        sort($variants);

        return [
            'propertyName' => $propertyName,
            'valueToVariant' => $valueToVariant,
            'variants' => $variants,
            'kind' => 'inline',
            'syntheticVariants' => $synthetic,
            'variantInheritsFrom' => [],
        ];
    }

    /**
     * The allOf-inheritance form: a base object that declares the discriminator
     * directly, with variants composed via `allOf: [{$ref: Base}, ...]`. The
     * variants are named components (already in the schema map), so they need no
     * synthesis, only the value map and an inheritance link so each variant does
     * not redeclare the base's properties.
     *
     * @param  array<string, Schema>  $schemas
     * @param  array<array-key, mixed>  $mapping
     * @param  list<string>  $variantNames  components whose allOf refs this base
     * @return array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>, kind: 'named'|'inline'|'allof', syntheticVariants: array<string, Schema>, variantInheritsFrom: array<string, string>}|null
     */
    private function analyzeAllOfInheritance(string $name, array $schemas, array $mapping, string $propertyName, array $variantNames): ?array
    {
        if ($variantNames === []) {
            // A discriminated base with no allOf variant claiming it cannot morph
            // to anything: degrade to presence-only and warn so it is visible.
            $this->warnInlineDegrade($name, 'an allOf-inheritance base has no variant composing it via allOf');

            return null;
        }

        // Every variant must be an object schema (they always are, being allOf
        // merges), and must not itself be a base.
        foreach ($variantNames as $variantName) {
            if (! $this->isObjectSchema($schemas[$variantName] ?? null)) {
                $this->warnInlineDegrade($name, sprintf('variant "%s" is not an object schema', $variantName));

                return null;
            }
        }

        $valueToVariant = $this->buildValueMap($variantNames, $mapping, $schemas);

        $inheritsFrom = [];
        foreach ($valueToVariant['variants'] as $variantName) {
            $inheritsFrom[$variantName] = $name;
        }

        return [
            'propertyName' => $propertyName,
            'valueToVariant' => $valueToVariant['map'],
            'variants' => $valueToVariant['variants'],
            'kind' => 'allof',
            'syntheticVariants' => [],
            'variantInheritsFrom' => $inheritsFrom,
        ];
    }

    /**
     * Build the discriminator-value => variant-name map for a set of named variant
     * components. Mapping entries win; a member without an explicit mapping value
     * uses its own schema name as the implicit value (per the OpenAPI spec).
     *
     * @param  list<string>  $memberNames
     * @param  array<array-key, mixed>  $mapping
     * @param  array<string, Schema>  $schemas
     * @return array{map: array<string, string>, variants: list<string>}
     */
    private function buildValueMap(array $memberNames, array $mapping, array $schemas): array
    {
        $valueToVariant = [];
        $variants = $memberNames;

        foreach ($mapping as $value => $target) {
            if (! is_string($target)) {
                continue;
            }
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $targetName = $this->resolveMappingTarget($target);
            if ($targetName === null || ! $this->isObjectSchema($schemas[$targetName] ?? null)) {
                continue;
            }
            $valueToVariant[$value] = $targetName;
            if (! in_array($targetName, $variants, true)) {
                $variants[] = $targetName;
            }
        }

        foreach ($memberNames as $memberName) {
            if (! in_array($memberName, $valueToVariant, true)) {
                $valueToVariant[$memberName] ??= $memberName;
            }
        }

        sort($variants);

        return ['map' => $valueToVariant, 'variants' => $variants];
    }

    /**
     * The discriminator value an inline member pins for itself via a `const` or a
     * single-value `enum` on its discriminator property, or null when it pins none.
     * This lets an inline union be discriminated even without a `mapping` block.
     */
    private function memberDiscriminatorValue(Schema $member, string $propertyName): ?string
    {
        $properties = $this->localProperties($member);
        $property = $properties[$propertyName] ?? null;
        if (! $property instanceof Schema) {
            return null;
        }

        // `const` is a JSON Schema keyword cebe does not expose as a typed property
        // (it surfaces via getSerializableData), so read it from the serialized
        // form, mirroring how ModelGenerator::constValue() reads it.
        $serialized = (array) $property->getSerializableData();
        if (array_key_exists('const', $serialized)) {
            $const = $serialized['const'];
            if (is_string($const) || is_int($const)) {
                return (string) $const;
            }
        }

        $enum = $property->enum;
        if (is_array($enum) && count($enum) === 1) {
            $value = $enum[0];
            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Resolve a discriminator mapping target that points INTO an inline oneOf/anyOf
     * (a JSON pointer like '#/.../oneOf/2') to the member index, or null when it is
     * not such a pointer or the index is out of range.
     */
    private function inlineMemberIndex(string $target, int $memberCount): ?int
    {
        if (! str_starts_with($target, '#/')) {
            return null;
        }
        $parts = explode('/', $target);
        $last = end($parts);
        if ($last === '' || preg_match('/^\d+$/', $last) !== 1) {
            return null;
        }
        $index = (int) $last;

        return $index >= 0 && $index < $memberCount ? $index : null;
    }

    /**
     * Synthesize a deterministic, collision-safe RAW schema name for an inline
     * variant, derived from the base name and the variant's discriminator value.
     * The name is sanitized to plain word characters and suffixed with a counter
     * until it does not collide with an existing component or earlier synthesized
     * name, so the generator's later identifier mapping stays collision-free too.
     *
     * @param  array<string, mixed>  $used  names already taken (components + synthesized)
     */
    private function synthesizeVariantName(string $baseName, string $value, array $used): string
    {
        $valuePart = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? '';
        $valuePart = trim($valuePart, '_');
        if ($valuePart === '') {
            $valuePart = 'Variant';
        }

        $candidate = $baseName.'_'.$valuePart;
        if (! isset($used[$candidate])) {
            return $candidate;
        }

        $counter = 2;
        while (isset($used[$candidate.'_'.$counter])) {
            $counter++;
        }

        return $candidate.'_'.$counter;
    }

    private function warnInlineDegrade(string $name, string $reason): void
    {
        $this->warnings[sprintf(
            'Discriminated union "%s" cannot be enforced (%s); it is generated as a presence-only "mixed" property '
            .'instead of a morphable base.',
            $name,
            $reason,
        )] = true;
    }

    /**
     * The `oneOf`/`anyOf` members of a schema in source order, deduplicated for
     * the all-ref case by reference but kept positional for the inline case.
     *
     * @return list<Schema|Reference>
     */
    private function unionMembers(Schema $schema): array
    {
        $members = [];
        if (is_array($schema->oneOf)) {
            foreach ($schema->oneOf as $member) {
                $members[] = $member;
            }
        }
        if (is_array($schema->anyOf)) {
            foreach ($schema->anyOf as $member) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * The locally declared properties of a schema (own `properties`, no allOf
     * merge), name => schema, used to read a member's discriminator const/enum.
     *
     * @return array<string, Schema|Reference>
     */
    private function localProperties(Schema $schema): array
    {
        $properties = $schema->properties;
        if (! is_array($properties)) {
            return [];
        }

        $result = [];
        foreach ($properties as $name => $property) {
            if ($property instanceof Schema || $property instanceof Reference) {
                $result[(string) $name] = $property;
            }
        }

        return $result;
    }

    /**
     * Resolve a discriminator `mapping` target to a component schema name. The
     * value may be a full `$ref` pointer (`#/components/schemas/Cat`) or a bare
     * schema name (`Cat`). Returns null when it is a $ref outside the components
     * schemas namespace.
     */
    private function resolveMappingTarget(string $target): ?string
    {
        if (str_starts_with($target, '#/')) {
            return $this->refName($target);
        }

        return $target === '' ? null : $target;
    }

    /**
     * Whether a schema is a plain object (so it can become a variant Data class).
     * An object has `type: object`, or named `properties`, or merges via `allOf`.
     * A scalar/array/oneOf/anyOf member is not an object variant.
     */
    private function isObjectSchema(?Schema $schema): bool
    {
        if ($schema === null) {
            return false;
        }

        // A composition-only member (its own oneOf/anyOf) is not a flat object.
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return false;
        }

        if ($this->notEmptyArray($schema->allOf)) {
            return true;
        }

        if ($this->notEmptyArray($schema->properties)) {
            return true;
        }

        $type = $schema->type;
        if (is_string($type)) {
            return $type === 'object';
        }
        if (is_array($type)) {
            return in_array('object', $type, true);
        }

        return false;
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

    private function notEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
