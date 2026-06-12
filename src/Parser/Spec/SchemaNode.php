<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Schema Object (issue #104). The replacement for cebe's Schema and for every
 * `getSerializableData()` escape hatch the emitter used: each keyword the
 * generator consumes is a first-class typed property, including the OpenAPI 3.1
 * keywords cebe never typed (`const`, `prefixItems`, `patternProperties`,
 * `dependentRequired`, `contentMediaType`) and the vendor deprecation
 * extensions.
 *
 * Absent-vs-explicit is representable everywhere it matters:
 *   - `hasDefault` / `hasConst` flags, because `default: null` and `const: null`
 *     are valid explicit values that a nullable property alone cannot encode.
 *   - `hasAdditionalProperties` distinguishes an absent key from an explicit
 *     `additionalProperties: true|false|{...}` (an absent key means an open
 *     object, which the closed-object rule, issue #30, must see through).
 *   - `exclusiveMinimum` / `exclusiveMaximum` are `int|float|bool|null` to carry
 *     both the 3.0 boolean form and the 3.1 numeric form verbatim.
 *   - `required` is `list<string>|bool|null` so the real-world boolean misuse
 *     (`required: true` on a property schema) survives hydration and can be
 *     warned about instead of crashing or being silently dropped.
 *
 * Anything not typed here lands in `extra` (unknown fixed-field-style keywords,
 * e.g. `xml`, `externalDocs`, `examples`, `$defs`) or `extensions` (`x-*` keys),
 * a raw passthrough so no spec information is destroyed.
 *
 * @internal
 */
final readonly class SchemaNode
{
    /**
     * @param  string|list<string>|null  $type  3.1 allows a type array
     * @param  list<string>|bool|null  $required  boolean form is the tolerated per-property misuse
     * @param  list<mixed>|null  $enum
     * @param  int|float|bool|null  $exclusiveMinimum  3.0 boolean form or 3.1 numeric form
     * @param  int|float|bool|null  $exclusiveMaximum  3.0 boolean form or 3.1 numeric form
     * @param  array<string, SchemaNode|ReferenceNode>|null  $properties
     * @param  SchemaNode|ReferenceNode|bool|null  $additionalProperties  null only when absent
     * @param  bool  $hasAdditionalProperties  true when the key is present in the spec
     * @param  array<string, SchemaNode|ReferenceNode>|null  $patternProperties  pattern to schema
     * @param  list<SchemaNode|ReferenceNode>|null  $allOf
     * @param  list<SchemaNode|ReferenceNode>|null  $oneOf
     * @param  list<SchemaNode|ReferenceNode>|null  $anyOf
     * @param  list<SchemaNode|ReferenceNode>|null  $prefixItems  3.1 tuple position schemas
     * @param  array<string, list<string>>|null  $dependentRequired  3.1 conditional required map
     * @param  mixed  $default  meaningful only when `$hasDefault` is true
     * @param  bool  $hasDefault  true when the `default` key is present, even for an explicit null
     * @param  mixed  $const  meaningful only when `$hasConst` is true
     * @param  bool  $hasConst  true when the `const` key is present, even for an explicit null
     * @param  string|null  $contentMediaType  3.1: declared media type of a string payload
     * @param  string|null  $xDeprecatedReason  vendor extension `x-deprecated-reason`
     * @param  string|null  $xDeprecationReason  vendor extension `x-deprecation-reason`
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys (raw passthrough)
     * @param  array<string, mixed>  $extra  untyped non-extension keywords (raw passthrough)
     */
    public function __construct(
        public string|array|null $type = null,
        public ?string $format = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?bool $nullable = null,
        public ?bool $deprecated = null,
        public ?bool $readOnly = null,
        public ?bool $writeOnly = null,
        public array|bool|null $required = null,
        public ?array $enum = null,
        public int|float|null $multipleOf = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public int|float|bool|null $exclusiveMinimum = null,
        public int|float|bool|null $exclusiveMaximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?int $minProperties = null,
        public ?int $maxProperties = null,
        public ?array $properties = null,
        public SchemaNode|ReferenceNode|bool|null $additionalProperties = null,
        public bool $hasAdditionalProperties = false,
        public ?array $patternProperties = null,
        public ?array $allOf = null,
        public ?array $oneOf = null,
        public ?array $anyOf = null,
        public SchemaNode|ReferenceNode|null $not = null,
        public SchemaNode|ReferenceNode|null $items = null,
        public ?array $prefixItems = null,
        public ?array $dependentRequired = null,
        public mixed $default = null,
        public bool $hasDefault = false,
        public mixed $const = null,
        public bool $hasConst = false,
        public ?string $contentMediaType = null,
        public ?DiscriminatorNode $discriminator = null,
        public mixed $example = null,
        public ?string $xDeprecatedReason = null,
        public ?string $xDeprecationReason = null,
        public array $extensions = [],
        public array $extra = [],
    ) {}
}
