<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Derives Laravel validation rules from schemas (issue #109, extracted from
 * ModelGenerator).
 *
 * One property's rules are built from its schema and resolved type: presence
 * (required/sometimes/nullable), the scalar constraint families
 * (string/integer/number/boolean), enum and const membership, array shape and
 * item wildcards, tuple positions, map values, dependentRequired, and the
 * closed-object rule. References resolve against the run's registry and alias
 * caches through the shared {@see GenerationState}.
 *
 * @internal
 */
final class RulesBuilder
{
    public function __construct(
        private readonly GenerationState $state,
    ) {}

    /**
     * Support-rule classes referenced by short name inside emitted rule
     * expressions (`new MultipleOfRule(...)`, `new Rfc3339DateTimeRule`). When a
     * rule string references one, its FQCN is resolved against the consumer's own
     * Support namespace and the class is recorded for inlining (issue #40).
     */
    public const RULE_CLASS_NAMES = [
        'HostnameRule',
        'Iso8601DurationRule',
        'MultipleOfRule',
        'NoUnknownPropertiesRule',
        'Rfc3339DateTimeRule',
        'Rfc3339TimeRule',
    ];

    /**
     * Candidate PCRE delimiters, tried in order. The first one not present in the
     * pattern is used so the pattern never needs internal escaping. None of these
     * are alphanumeric, backslash, or whitespace, all of which PCRE forbids as
     * delimiters.
     *
     * @var list<string>
     */
    private const REGEX_DELIMITERS = ['#', '~', '/', '!', '@', '%', '|', ';', ',', '=', '+'];

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
    public function buildRules(SchemaNode|ReferenceNode $schema, bool $required, ResolvedType $type): array
    {
        $rules = $this->presenceRules($required, $type->nullable);

        if ($schema instanceof ReferenceNode) {
            // A $ref to a pure-map component: the value is a typed array, so the
            // property rule is 'array' plus the map's own key-count bounds
            // (minProperties/maxProperties, issue #72) and a wildcard value rule
            // derived from the map's value schema.
            $mapSchema = $this->state->referencedMapSchema($schema);
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
            $aliasSchema = $this->state->referencedAliasSchema($schema);
            if ($aliasSchema !== null) {
                $terminal = $this->state->terminalAliasSchema($aliasSchema);

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
            $objectSchema = $this->state->referencedObjectSchema($schema);
            if ($objectSchema !== null && (SchemaFacts::normalizeTypes($objectSchema)[0] ?? null) === 'object') {
                $countRules = $this->objectCountRules($objectSchema);
                if ($countRules !== []) {
                    return [array_merge($rules, ["'array'"], $countRules), [], false];
                }
            }

            return [$rules, [], false];
        }

        // A pure-map property: 'array' plus the map's own key-count bounds
        // (minProperties/maxProperties, issue #72) and a wildcard value rule.
        if (SchemaFacts::isPureMap($schema)) {
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
            $values = SchemaFacts::enumValues($schema);
            if ($values !== []) {
                $rules[] = 'Rule::in(['.implode(', ', array_map(fn (string|int|float|bool $value): string => PhpLiteral::scalarLiteral($value), $values)).'])';

                return [$rules, [], true];
            }
        }

        // `const` is a single-value enum: enforce the one allowed value with
        // Rule::in, reusing the enum machinery and scalar-literal escaping.
        $const = SchemaFacts::constValue($schema);
        if ($const !== null) {
            $rules[] = 'Rule::in(['.PhpLiteral::scalarLiteral($const[0]).'])';

            return [$rules, [], true];
        }

        $types = SchemaFacts::normalizeTypes($schema);

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
            // (see OpenApiReader) and lands in the count rules.
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
    private function arrayWildcardRules(SchemaNode $schema, string $suffix): array
    {
        $items = $schema->items;
        $map = [];

        if ($items instanceof SchemaNode && (SchemaFacts::normalizeTypes($items)[0] ?? null) === 'array') {
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
    public function presenceRules(bool $required, bool $nullable): array
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
     * @param  array<string, SchemaNode|ReferenceNode>  $properties  the full merged property map
     * @param  array<string, bool>  $declaredNullable  wire name => nullable, for the properties THIS class declares
     * @param  array<string, list<string>>  $rules
     */
    public function applyDependentRequired(string $base, SchemaNode $schema, array $required, array $properties, array $declaredNullable, array &$rules): void
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
                    $this->state->warnings[sprintf(
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
            $expression = "'".PhpLiteral::escapeSingleQuoted($name.implode(',', $usable))."'";

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
    private function mergedDependentRequired(SchemaNode $schema, array $seen = []): array
    {
        $map = $this->localDependentRequired($schema);

        $members = $schema->allOf;
        if (! is_array($members)) {
            return $map;
        }

        foreach ($members as $member) {
            $resolved = $this->state->resolveMemberSchema($member, $seen);
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
     * The schema's own `dependentRequired` map, read from the first-class typed
     * keyword on SchemaNode (issue #104). The spec is untrusted input: only the
     * well-formed shape (a non-empty trigger name mapping to non-empty string
     * names) is accepted, anything else is ignored.
     *
     * @return array<string, list<string>>
     */
    private function localDependentRequired(SchemaNode $schema): array
    {
        $raw = $schema->dependentRequired;
        if ($raw === null) {
            return [];
        }

        $map = [];
        foreach ($raw as $trigger => $dependents) {
            $trigger = (string) $trigger;
            if ($trigger === '') {
                continue;
            }

            $names = [];
            foreach ($dependents as $dependent) {
                if ($dependent !== '') {
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
    private function itemRules(SchemaNode $schema): array
    {
        $items = $schema->items;

        if ($items instanceof ReferenceNode) {
            $enumClass = $this->referencedEnumClass($items);

            return $enumClass !== null ? [['Rule::enum('.$enumClass.'::class)'], true] : [null, false];
        }

        if (! $items instanceof SchemaNode) {
            return [null, false];
        }

        if ($this->notEmptyArray($items->enum)) {
            $values = SchemaFacts::enumValues($items);
            if ($values !== []) {
                return [['Rule::in(['.implode(', ', array_map(fn (string|int|float|bool $value): string => PhpLiteral::scalarLiteral($value), $values)).'])'], true];
            }
        }

        $primary = SchemaFacts::normalizeTypes($items)[0] ?? null;

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
    private function mapValueRules(SchemaNode $schema): array
    {
        $value = SchemaFacts::additionalPropertiesSchema($schema);

        if ($value === true || $value === null) {
            return [null, false];
        }

        if ($value instanceof ReferenceNode) {
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
    private function inlineValueRules(SchemaNode $value): array
    {
        if ($this->notEmptyArray($value->enum)) {
            $values = SchemaFacts::enumValues($value);
            if ($values !== []) {
                return [['Rule::in(['.implode(', ', array_map(fn (string|int|float|bool $v): string => PhpLiteral::scalarLiteral($v), $values)).'])'], true];
            }
        }

        $primary = SchemaFacts::normalizeTypes($value)[0] ?? null;

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
     * zero-based position. A first-class typed keyword on SchemaNode (issue
     * #104): the reader already hydrated every position into the node graph,
     * so no on-the-fly schema construction happens here anymore. The spec is
     * untrusted input: the reader dropped malformed entries during hydration,
     * and a `prefixItems` that is not a list at all never reached the typed
     * property. A non-empty result signals that the schema IS a tuple, which
     * suppresses the post-prefix `items` wildcard rules (they would
     * false-reject valid prefix positions).
     *
     * @return array<int, SchemaNode|ReferenceNode>
     */
    private function prefixItemSchemas(SchemaNode $schema): array
    {
        return $schema->prefixItems ?? [];
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
     * @param  array<int, SchemaNode|ReferenceNode>  $positions
     * @return array{0: array<string, list<string>>, 1: bool} rules keyed by index suffix, whether Rule:: is used
     */
    private function prefixItemRules(array $positions): array
    {
        $map = [];
        $uses = false;

        foreach ($positions as $index => $position) {
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
    private function prefixItemValueRules(SchemaNode|ReferenceNode $position): array
    {
        if ($position instanceof ReferenceNode) {
            $enumClass = $this->referencedEnumClass($position);
            if ($enumClass !== null) {
                return [['Rule::enum('.$enumClass.'::class)'], true];
            }

            // A scalar/array/union alias position enforces its terminal
            // schema's constraints, exactly like an alias at a property site.
            $aliasSchema = $this->state->referencedAliasSchema($position);
            if ($aliasSchema !== null) {
                return $this->prefixItemValueRules($this->state->terminalAliasSchema($aliasSchema));
            }

            // A map or object component arrives as a raw array: assert the
            // shape only (nested hydration is a typing concern, not a rules
            // one). An unresolvable $ref stays presence-only.
            if ($this->state->referencedMapSchema($position) !== null || $this->state->referencedObjectSchema($position) !== null) {
                return [["'array'"], false];
            }

            return [null, false];
        }

        // A multi-type position (`type: ["string", "integer"]`) and a
        // composition keyword stay presence-only: a single type rule would
        // wrongly reject the other valid members (mirrors buildRules()).
        if (count(SchemaFacts::normalizeTypes($position)) > 1
            || $this->notEmptyArray($position->oneOf)
            || $this->notEmptyArray($position->anyOf)
            || $this->notEmptyArray($position->allOf)) {
            return [null, false];
        }

        [$rules, $uses] = $this->inlineValueRules($position);

        if ($rules !== null && SchemaFacts::isNullable($position)) {
            $rules = array_merge(["'nullable'"], $rules);
        }

        return [$rules, $uses];
    }

    /**
     * @return list<string>
     */
    private function stringRules(SchemaNode $schema): array
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
     * so emit `gt:N` on its own. SchemaNode carries both forms verbatim in one
     * `int|float|bool|null` property (issue #104), null when absent, so an
     * explicit `exclusiveMinimum: false` (inclusive) never reads as a bound.
     *
     * @return list<string>
     */
    private function numericRules(SchemaNode $schema): array
    {
        $rules = [];

        $exclusiveMin = $schema->exclusiveMinimum;
        $exclusiveMax = $schema->exclusiveMaximum;

        // 3.1 numeric exclusiveMinimum: a strict lower bound on its own.
        if (is_int($exclusiveMin) || is_float($exclusiveMin)) {
            $rules[] = "'gt:".PhpLiteral::numberLiteral($exclusiveMin)."'";
        }
        if (is_int($exclusiveMax) || is_float($exclusiveMax)) {
            $rules[] = "'lt:".PhpLiteral::numberLiteral($exclusiveMax)."'";
        }

        $min = $schema->minimum;
        if (is_int($min) || is_float($min)) {
            // 3.0 boolean exclusiveMinimum: true upgrades the bound to strict.
            $rules[] = $exclusiveMin === true
                ? "'gt:".PhpLiteral::numberLiteral($min)."'"
                : "'min:".PhpLiteral::numberLiteral($min)."'";
        }

        $max = $schema->maximum;
        if (is_int($max) || is_float($max)) {
            $rules[] = $exclusiveMax === true
                ? "'lt:".PhpLiteral::numberLiteral($max)."'"
                : "'max:".PhpLiteral::numberLiteral($max)."'";
        }

        $multipleOf = $schema->multipleOf;
        if ((is_int($multipleOf) || is_float($multipleOf)) && $multipleOf > 0) {
            $rules[] = 'new MultipleOfRule('.PhpLiteral::numberLiteral($multipleOf).')';
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    public function arrayCountRules(SchemaNode $schema): array
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
    private function objectCountRules(SchemaNode $schema): array
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

    private function regexRule(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }

        return "'regex:".PhpLiteral::escapeSingleQuoted($this->delimitedPattern($pattern))."'";
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

    private function referencedEnumClass(ReferenceNode $reference): ?string
    {
        $name = SchemaPointer::refName($reference->pointer());

        if ($name !== null && isset($this->state->registry[$name]) && $this->state->registry[$name]['kind'] === 'enum') {
            // Every Rule::enum(X::class) expression flows through here, so
            // recording the reference at this single chokepoint lets the
            // grouped layout (issue #93) import an enum a rule references even
            // when the property type itself does not mention it.
            $this->state->noteClassRef($this->state->registry[$name]['class']);

            return $this->state->registry[$name]['class'];
        }

        return null;
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
    public function closedObjectRule(string $schemaName, array $wireNames, SchemaNode $schema): ?string
    {
        $patterns = SchemaFacts::patternPropertyPatterns($schema);

        if ($patterns === []) {
            return 'new NoUnknownPropertiesRule('.PhpLiteral::stringListLiteral($wireNames).')';
        }

        $delimited = [];
        foreach ($patterns as $pattern) {
            $candidate = $this->delimitedPattern($pattern);
            if (! $this->compilesAsPcre($candidate)) {
                $this->state->warnings[sprintf(
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

        $this->state->warnings[sprintf(
            'Schema "%s" combines additionalProperties: false with patternProperties. '
            .'Keys matching a pattern are accepted by the closed-object rule, but their value schemas are not validated.',
            $schemaName,
        )] = true;

        return 'new NoUnknownPropertiesRule('
            .PhpLiteral::stringListLiteral($wireNames)
            .', '
            .PhpLiteral::stringListLiteral($delimited)
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
