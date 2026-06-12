<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Stateless schema-classification queries shared across the emitter layer.
 *
 * Every question here is answered from the schema node alone (no registry, no
 * per-run state), so the methods are static. Extracted from ModelGenerator
 * (issue #109) so the type resolver, the rules builder, and the enum emitter
 * read the spec through one shared vocabulary.
 *
 * @internal
 */
final class SchemaFacts
{
    /**
     * OpenAPI scalar type => PHP type.
     *
     * @var array<string, string>
     */
    public const SCALARS = [
        'string' => 'string',
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
    ];

    /**
     * @return list<string>
     */
    public static function normalizeTypes(SchemaNode $schema): array
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

    public static function isNullable(SchemaNode $schema): bool
    {
        if ($schema->nullable === true) {
            return true;
        }

        $raw = $schema->type;

        return is_array($raw) && in_array('null', $raw, true);
    }

    /**
     * The `const` keyword (JSON Schema / OpenAPI 3.1) constrains a value to a
     * single literal. A first-class typed keyword on SchemaNode with a presence
     * flag (issue #104). Only scalar literals (string/int) we can both type and
     * enforce with Rule::in are accepted; anything else (array/object/bool/
     * float/null const) yields null and the schema is handled by the normal
     * type path.
     *
     * Returns a single-element list so callers can distinguish "no const"
     * (null) from "const present" ([value]) even for falsy values.
     *
     * @return array{0: string|int}|null
     */
    public static function constValue(SchemaNode $schema): ?array
    {
        if (! $schema->hasConst) {
            return null;
        }

        $value = $schema->const;

        if (is_string($value) || is_int($value)) {
            return [$value];
        }

        return null;
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
    public static function enumValues(SchemaNode $schema): array
    {
        $result = [];
        foreach (self::asArray($schema->enum) as $value) {
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
    public static function isEnum(SchemaNode $schema): bool
    {
        if (! self::notEmptyArray($schema->enum)) {
            return false;
        }

        if (self::notEmptyArray($schema->properties)) {
            return false;
        }

        $values = self::enumValues($schema);
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
    public static function isScalarEnumComponent(SchemaNode $schema): bool
    {
        if (! self::notEmptyArray($schema->enum)) {
            return false;
        }

        if (self::notEmptyArray($schema->properties)) {
            return false;
        }

        if (self::isEnum($schema)) {
            return false;
        }

        return self::enumValues($schema) !== [];
    }

    /**
     * The explicit `additionalProperties` value schema for a map, or null when
     * the schema is not an explicitly-declared map.
     *
     * The node's `hasAdditionalProperties` presence flag (issue #104) tells an
     * explicit `additionalProperties: true` apart from a plain object that
     * never declared the key. We treat the map as present only when the spec
     * set the key to a value other than `false`.
     *
     * Returns the value node for a typed map, or boolean `true` (as a marker)
     * for `additionalProperties: true`. Returns null otherwise.
     */
    public static function additionalPropertiesSchema(SchemaNode $schema): SchemaNode|ReferenceNode|true|null
    {
        if (! $schema->hasAdditionalProperties) {
            return null;
        }

        $value = $schema->additionalProperties;

        if ($value instanceof SchemaNode || $value instanceof ReferenceNode) {
            return $value;
        }

        // additionalProperties: true (untyped map); false is not a map.
        return $value === true ? true : null;
    }

    /**
     * Whether the schema explicitly declares `additionalProperties: false`, the
     * closed-object marker (issue #30). The node's `hasAdditionalProperties`
     * presence flag (issue #104) tells an explicit `false` apart from an absent
     * key. Returns true only when the spec set the key to literal `false`.
     */
    public static function declaresClosedObject(SchemaNode $schema): bool
    {
        return $schema->hasAdditionalProperties
            && $schema->additionalProperties === false;
    }

    /**
     * Whether a component schema is a pure map: an object whose only content is
     * `additionalProperties` (a typed, ref, or untyped value), with no named
     * `properties` and no composition keyword. Such a schema is a typed array,
     * not a Data class. A mixed object (named properties AND
     * additionalProperties) is NOT a pure map: its named properties are emitted
     * normally and the dynamic overflow is documented as not captured.
     */
    public static function isPureMap(SchemaNode $schema): bool
    {
        if (self::additionalPropertiesSchema($schema) === null) {
            return false;
        }

        if (self::notEmptyArray($schema->properties)) {
            return false;
        }

        if (self::notEmptyArray($schema->allOf)
            || self::notEmptyArray($schema->oneOf)
            || self::notEmptyArray($schema->anyOf)
        ) {
            return false;
        }

        if (self::notEmptyArray($schema->enum)) {
            return false;
        }

        $types = self::normalizeTypes($schema);
        $primary = $types[0] ?? null;

        // Only an object (or an untyped schema that is map-shaped) is a map.
        return $primary === 'object' || $primary === null;
    }

    /**
     * The `patternProperties` patterns a schema declares, in spec order, read
     * from the first-class typed keyword on SchemaNode (issue #104). The value
     * schemas are intentionally ignored: only key admission is derived from
     * them (see RulesBuilder::closedObjectRule()).
     *
     * @return list<string>
     */
    public static function patternPropertyPatterns(SchemaNode $schema): array
    {
        $patterns = [];
        foreach (array_keys($schema->patternProperties ?? []) as $pattern) {
            $patterns[] = (string) $pattern;
        }

        return array_values(array_unique($patterns));
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
    public static function bareAllOfRef(SchemaNode $schema): ?ReferenceNode
    {
        if (self::notEmptyArray($schema->properties)) {
            return null;
        }

        $members = $schema->allOf;
        if (! is_array($members) || count($members) !== 1) {
            return null;
        }

        $member = $members[0];
        if (! $member instanceof ReferenceNode) {
            return null;
        }

        return SchemaPointer::refName($member->pointer()) !== null ? $member : null;
    }

    /**
     * @return array<string, SchemaNode|ReferenceNode>
     */
    public static function localProperties(SchemaNode $schema): array
    {
        $result = [];
        foreach (self::asArray($schema->properties) as $name => $property) {
            if ($property instanceof SchemaNode || $property instanceof ReferenceNode) {
                $result[(string) $name] = $property;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function localRequired(SchemaNode $schema): array
    {
        $result = [];
        foreach (self::asArray($schema->required) as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    public static function isReadOnly(SchemaNode|ReferenceNode $schema): bool
    {
        return $schema instanceof SchemaNode && $schema->readOnly === true;
    }

    public static function isWriteOnly(SchemaNode|ReferenceNode $schema): bool
    {
        return $schema instanceof SchemaNode && $schema->writeOnly === true;
    }

    /**
     * Whether a schema (a component or a single property) is marked
     * `deprecated: true`. SchemaNode types `deprecated` as a nullable strict
     * bool, so a missing or mistyped flag never reads as deprecated.
     */
    public static function isDeprecated(SchemaNode|ReferenceNode $schema): bool
    {
        return $schema instanceof SchemaNode && $schema->deprecated === true;
    }

    /**
     * The deprecation docblock line for a deprecated schema (the literal
     * "at-deprecated" tag, optionally followed by a reason), including a short
     * reason when the spec supplies one via the vendor extension
     * `x-deprecated-reason` or `x-deprecation-reason`. Returns null when the
     * schema is not deprecated. The reason is spec-derived (untrusted), so it is
     * run through docblockSafe() before being embedded in the comment.
     */
    public static function deprecationTag(SchemaNode|ReferenceNode $schema): ?string
    {
        if (! self::isDeprecated($schema)) {
            return null;
        }

        $reason = self::deprecationReason($schema);

        return $reason !== null ? '@deprecated '.$reason : '@deprecated';
    }

    /**
     * The deprecation reason text from a vendor extension, sanitized, or null
     * when none is present or it sanitizes to empty. Both the singular
     * `x-deprecated-reason` and the alternate `x-deprecation-reason` spellings
     * are accepted; the first non-empty one wins (sorted-key independent: the two
     * are checked in a fixed order for determinism).
     */
    private static function deprecationReason(SchemaNode|ReferenceNode $schema): ?string
    {
        if (! $schema instanceof SchemaNode) {
            return null;
        }

        foreach ([$schema->xDeprecatedReason, $schema->xDeprecationReason] as $value) {
            if ($value !== null) {
                $safe = PhpLiteral::docblockSafe($value);
                if ($safe !== '') {
                    return $safe;
                }
            }
        }

        return null;
    }

    private static function notEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
