<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;

/**
 * Pre-pass over the component schemas that finds discriminated object unions and
 * records, for each one, how it morphs at runtime.
 *
 * The supported shape (Phase A) is a NAMED component schema that is a
 * `oneOf`/`anyOf` of `$ref`s plus a `discriminator`:
 *
 *   Pet:
 *     oneOf: [{$ref: Cat}, {$ref: Dog}]
 *     discriminator:
 *       propertyName: petType
 *       mapping: {cat: '#/.../Cat', dog: '#/.../Dog'}   # optional
 *
 * The discriminator value for each variant is its `mapping` entry when present,
 * otherwise (per the OpenAPI spec) the variant's component schema name. Every
 * member must resolve to an OBJECT schema; if any member is a scalar/array/
 * non-object the schema is NOT a clean discriminated object union and is skipped
 * here, so the caller keeps the existing presence-only (`mixed`) behavior.
 *
 * A variant referenced by more than one discriminated base keeps the FIRST base
 * (PHP has single inheritance); later bases that would claim it are skipped, and
 * those bases fall back to the existing behavior. This keeps the registry a clean
 * tree and never crashes.
 *
 * The registry is built once and consulted by ModelGenerator. It is purely a
 * lookup table: it does not emit anything and holds no PHP identifiers (the
 * generator owns naming), only raw schema names. Ordering is deterministic:
 * component schemas are scanned in sorted order.
 */
final class DiscriminatorRegistry
{
    /**
     * Base schema name => discriminator details.
     *
     * @var array<string, array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>}>
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

        $candidates = [];
        foreach ($schemas as $name => $schema) {
            $candidate = $this->analyze($name, $schema, $schemas);
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

            $this->bases[$baseName] = [
                'propertyName' => $candidate['propertyName'],
                'valueToVariant' => $valueToVariant,
                'variants' => $claimed,
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
     * Classify one component schema as a discriminated-union candidate, or null
     * when it is not the supported shape. Does not yet apply the single-base
     * claiming rule (that runs over all candidates afterwards), so the returned
     * `variants` list is the full member set.
     *
     * @param  array<string, Schema>  $schemas
     * @return array{propertyName: string, valueToVariant: array<string, string>, variants: list<string>}|null
     */
    private function analyze(string $name, Schema $schema, array $schemas): ?array
    {
        $discriminator = $schema->discriminator;
        if ($discriminator === null) {
            return null;
        }

        $propertyName = $discriminator->propertyName;
        if (! is_string($propertyName) || $propertyName === '') {
            return null;
        }

        $members = $this->memberRefNames($schema);
        if ($members === []) {
            return null;
        }

        // Every member must resolve to an OBJECT schema. A scalar/array/union
        // member means this is not a clean discriminated object union: bail so the
        // caller keeps the existing presence-only behavior. This is a genuine
        // degrade (the schema has a discriminator and a oneOf/anyOf of $refs), so
        // warn rather than dropping it silently.
        foreach ($members as $memberName) {
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

        // Build value => variant. Mapping wins when present; otherwise the
        // implicit value is the variant's own schema name.
        $valueToVariant = [];
        $variants = $members;

        $mapping = is_array($discriminator->mapping) ? $discriminator->mapping : [];
        foreach ($mapping as $value => $target) {
            if (! is_string($value) || $value === '' || ! is_string($target)) {
                continue;
            }
            $targetName = $this->resolveMappingTarget($target);
            if ($targetName === null || ! $this->isObjectSchema($schemas[$targetName] ?? null)) {
                // A mapping target that is not a known object schema is ignored;
                // the member set still drives the variants.
                continue;
            }
            $valueToVariant[$value] = $targetName;
            if (! in_array($targetName, $variants, true)) {
                $variants[] = $targetName;
            }
        }

        // Members without an explicit mapping value use their schema name as the
        // implicit discriminator value. Every member is reachable through some
        // value, so the value map is never empty here.
        foreach ($members as $memberName) {
            if (! in_array($memberName, $valueToVariant, true)) {
                $valueToVariant[$memberName] ??= $memberName;
            }
        }

        sort($variants);

        return [
            'propertyName' => $propertyName,
            'valueToVariant' => $valueToVariant,
            'variants' => $variants,
        ];
    }

    /**
     * The component schema names referenced by the `oneOf`/`anyOf` members of a
     * schema, in source order. An inline (non-$ref) member, or a $ref pointing
     * outside `#/components/schemas/`, makes the union non-clean: return [] so the
     * schema is not treated as a discriminated base.
     *
     * @return list<string>
     */
    private function memberRefNames(Schema $schema): array
    {
        $sets = [];
        if (is_array($schema->oneOf)) {
            $sets[] = $schema->oneOf;
        }
        if (is_array($schema->anyOf)) {
            $sets[] = $schema->anyOf;
        }

        if ($sets === []) {
            return [];
        }

        $names = [];
        foreach ($sets as $set) {
            foreach ($set as $member) {
                if (! $member instanceof Reference) {
                    return [];
                }
                $refName = $this->refName($member->getReference());
                if ($refName === null) {
                    return [];
                }
                if (! in_array($refName, $names, true)) {
                    $names[] = $refName;
                }
            }
        }

        return $names;
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
