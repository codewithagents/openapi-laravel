<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use cebe\openapi\spec\OpenApi;
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
 */
final class ModelGenerator
{
    private const SCALARS = [
        'string' => 'string',
        'integer' => 'int',
        'number' => 'float',
        'boolean' => 'bool',
    ];

    private UniqueNames $names;

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

    public function __construct(
        private readonly GeneratorOptions $options = new GeneratorOptions,
    ) {
        $this->names = new UniqueNames;
    }

    /**
     * @return array<string, GeneratedFile> class name => file, ordered by class name
     */
    public function generate(OpenApi $document): array
    {
        $this->names = new UniqueNames;
        $this->registry = [];
        $this->writeClasses = [];
        $this->mapAliases = [];
        $this->mapSchemas = [];
        $this->aliasTypes = [];
        $this->aliasSchemas = [];
        $this->files = [];

        $schemas = $this->componentSchemas($document);
        ksort($schemas);

        foreach ($schemas as $name => $schema) {
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
        }

        // Second classification pass: resolve each pure-map component to its
        // array type. Done after the registry is built so a map whose value is a
        // `$ref` resolves the referenced class. Sorted for determinism.
        foreach ($schemas as $name => $schema) {
            if ($this->isPureMap($schema)) {
                $this->mapSchemas[$name] = $schema;
                $this->mapAliases[$name] = $this->mapType($schema, PhpIdentifier::toClassName($name), 0, 'all');
            }
        }

        // Record the schemas of non-object alias components first (so rules at a
        // use site can recover the underlying schema), then resolve each to its
        // underlying type. Resolution is lazy and transitive: an alias whose
        // type is itself a `$ref` to another alias (alias -> alias) chains
        // through resolveReference, guarded against cycles. Sorted for
        // determinism.
        foreach ($schemas as $name => $schema) {
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
            if ($entry['kind'] === 'enum') {
                $this->emitEnum($entry['class'], $entry['schema']);
            } elseif ($this->hasReadWriteFlags($entry['schema'])) {
                // The spec marks fields readOnly/writeOnly: split into a read
                // variant (drops writeOnly) and a write variant (drops readOnly).
                $this->emitData($entry['class'], $entry['schema'], 0, 'read');
                $writeClass = $this->names->reserve($this->withSuffix($this->stripSuffix($entry['class']).'Writable'));
                $this->writeClasses[$name] = $writeClass;
                $this->emitData($writeClass, $entry['schema'], 0, 'write');
            } else {
                $this->emitData($entry['class'], $entry['schema'], 0, 'all');
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
     * @return array<string, Schema>
     */
    private function componentSchemas(OpenApi $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($components->schemas as $name => $schema) {
            // Component entries that are bare $refs (aliases) are skipped in v1.
            if ($schema instanceof Schema) {
                $result[(string) $name] = $schema;
            }
        }

        return $result;
    }

    private function emitData(string $className, Schema $schema, int $depth, string $variant = 'all'): void
    {
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

        foreach ($properties as $rawName => $propertySchema) {
            // Numeric property names ("200") are coerced to int array keys by PHP.
            $wireName = (string) $rawName;

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
            $type = $this->resolveType($propertySchema, $base.PhpIdentifier::toClassName($wireName), $depth + 1, $variant);

            // A scalar `default` makes the property optional on input even when
            // the spec lists it as required: an omitted value is filled by the
            // default, so the input rule is `sometimes`, not `required`. The
            // default also seeds the constructor parameter (`= 5`) instead of the
            // hardcoded `= null`, and a property carrying a default never sits in
            // the required-parameter group (PHP defaulted params must come last).
            $default = $this->defaultValue($propertySchema, $type);
            $isRequired = $listedRequired && $default === null;

            $rendered = $this->renderProperty($wireName, $propertyName, $type, $isRequired, $default);

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            // Validation rules are keyed by the wire (mapped input) name.
            [$propertyRules, $itemRules, $uses] = $this->buildRules($propertySchema, $isRequired, $type);
            $rules[$wireName] = $propertyRules;
            if ($itemRules !== null && $itemRules !== []) {
                $rules[$wireName.'.*'] = $itemRules;
            }
            $usesRule = $usesRule || $uses;
        }

        $params = array_merge($paramsRequired, $paramsOptional);
        $imports = $this->collectImports($params, $usesRule, $rules);

        // A mixed object (named properties AND additionalProperties) emits its
        // named properties normally. laravel-data cannot route unknown keys into
        // a designated property without a custom cast, so the dynamic overflow
        // is documented, not silently dropped into a non-functional field.
        $classDoc = $this->notEmptyArray($properties) && $this->additionalPropertiesSchema($schema) !== null
            ? 'This schema also declares additionalProperties: dynamic keys beyond the named properties above are not captured by this class.'
            : null;

        $this->files[$className] = new GeneratedFile(
            $className,
            $this->renderDataClass($className, $params, $imports, $rules, $classDoc),
        );
    }

    /**
     * Support-rule classes referenced by name inside emitted rule expressions
     * (`new MultipleOfRule(...)`, `new Rfc3339DateTimeRule`). Keyed by the short
     * class name the generated code uses; the value is the FQCN to import.
     *
     * @var array<string, string>
     */
    private const RULE_CLASS_IMPORTS = [
        'MultipleOfRule' => 'CodeWithAgents\\OpenApiLaravel\\Support\\MultipleOfRule',
        'Rfc3339DateTimeRule' => 'CodeWithAgents\\OpenApiLaravel\\Support\\Rfc3339DateTimeRule',
    ];

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
        $ruleText = '';
        foreach ($rules as $expressions) {
            $ruleText .= implode(' ', $expressions).' ';
        }
        foreach (self::RULE_CLASS_IMPORTS as $shortName => $fqcn) {
            if (str_contains($ruleText, 'new '.$shortName)) {
                $imports[] = $fqcn;
            }
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $imports;
    }

    /**
     * @param  array{0: string}|null  $default  the rendered scalar default expression, wrapped, or null for "no default"
     * @return array{code: string, imports: list<string>}
     */
    private function renderProperty(string $wireName, string $propertyName, ResolvedType $type, bool $isRequired, ?array $default = null): array
    {
        $imports = $type->imports;
        $lines = [];

        if ($type->docType !== null) {
            $lines[] = '        /** @var '.$type->docType.' */';
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
            $imports[] = 'CodeWithAgents\\OpenApiLaravel\\Support\\MapObjectTransformer';
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

        if (is_bool($value)) {
            return [$value ? 'true' : 'false'];
        }

        if (is_int($value) || is_float($value)) {
            return [$this->numberLiteral($value)];
        }

        if (is_string($value)) {
            return ["'".$this->escapeSingleQuoted($value)."'"];
        }

        // An array/object/null default is not a scalar literal we seed here.
        return null;
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

        // An optional property defaults to null, so the union must accept null.
        // A union spells that as a trailing `|null` member ('string|int|null'),
        // never the `?` shorthand, which PHP forbids on a union.
        if ($type->isUnion) {
            return str_ends_with($type->declaration, '|null') ? $type->declaration : $type->declaration.'|null';
        }

        return '?'.$type->declaration;
    }

    /**
     * Derive Laravel validation rules for a property from its schema.
     *
     * @return array{0: list<string>, 1: ?list<string>, 2: bool} property rules,
     *                                                           optional per-item rules (arrays), whether Rule:: is used
     */
    private function buildRules(Schema|Reference $schema, bool $required, ResolvedType $type): array
    {
        $rules = $this->presenceRules($required, $type->nullable);

        if ($schema instanceof Reference) {
            // A $ref to a pure-map component: the value is a typed array, so the
            // property rule is 'array' plus a wildcard value rule derived from
            // the map's value schema.
            $mapSchema = $this->referencedMapSchema($schema);
            if ($mapSchema !== null) {
                [$valueRules, $valueUses] = $this->mapValueRules($mapSchema);

                return [array_merge($rules, ["'array'"]), $valueRules, $valueUses];
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

                return [$rules, null, true];
            }

            return [$rules, null, false];
        }

        // A pure-map property: 'array' plus a wildcard value rule.
        if ($this->isPureMap($schema)) {
            [$valueRules, $valueUses] = $this->mapValueRules($schema);

            return [array_merge($rules, ["'array'"]), $valueRules, $valueUses];
        }

        // oneOf/anyOf stay presence-only (no variant enforcement). allOf is an
        // object shape after merging, so it also gets presence-only rules here;
        // its member properties carry their own rules in the nested Data class.
        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf) || $this->notEmptyArray($schema->allOf)) {
            return [$rules, null, false];
        }

        if ($this->notEmptyArray($schema->enum)) {
            $values = $this->enumValues($schema);
            if ($values !== []) {
                $rules[] = 'Rule::in(['.implode(', ', array_map(fn (string|int|float $value): string => $this->scalarLiteral($value), $values)).'])';

                return [$rules, null, true];
            }
        }

        // `const` is a single-value enum: enforce the one allowed value with
        // Rule::in, reusing the enum machinery and scalar-literal escaping.
        $const = $this->constValue($schema);
        if ($const !== null) {
            $rules[] = 'Rule::in(['.$this->scalarLiteral($const[0]).'])';

            return [$rules, null, true];
        }

        $types = $this->normalizeTypes($schema);

        // A multi-type union (`type: ["string", "integer"]`) stays presence-only:
        // a single type rule (`'string'`) would wrongly reject the other valid
        // members. This mirrors the presence-only handling of oneOf/anyOf unions.
        if (count($types) > 1) {
            return [$rules, null, false];
        }

        $primary = $types[0] ?? null;

        if ($primary === 'string') {
            return [array_merge($rules, $this->stringRules($schema)), null, false];
        }

        if ($primary === 'integer') {
            return [array_merge($rules, ["'integer'"], $this->numericRules($schema)), null, false];
        }

        if ($primary === 'number') {
            return [array_merge($rules, ["'numeric'"], $this->numericRules($schema)), null, false];
        }

        if ($primary === 'boolean') {
            return [array_merge($rules, ["'boolean'"]), null, false];
        }

        if ($primary === 'array') {
            [$itemRules, $itemUses] = $this->itemRules($schema);

            // `uniqueItems: true` requires every element to be distinct. Laravel
            // expresses that with a `distinct` rule on the `field.*` wildcard, so
            // append it to the item rules (creating them if the items carried
            // none of their own).
            if ($schema->uniqueItems === true) {
                $itemRules = array_merge($itemRules ?? [], ["'distinct'"]);
            }

            return [array_merge($rules, ["'array'"], $this->arrayCountRules($schema)), $itemRules, $itemUses];
        }

        return [$rules, null, false];
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
                return [['Rule::in(['.implode(', ', array_map(fn (string|int|float $value): string => $this->scalarLiteral($value), $values)).'])'], true];
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

        if ($this->notEmptyArray($value->enum)) {
            $values = $this->enumValues($value);
            if ($values !== []) {
                return [['Rule::in(['.implode(', ', array_map(fn (string|int|float $v): string => $this->scalarLiteral($v), $values)).'])'], true];
            }
        }

        $primary = $this->normalizeTypes($value)[0] ?? null;

        $rules = match ($primary) {
            'string' => $this->stringRules($value),
            'integer' => array_merge(["'integer'"], $this->numericRules($value)),
            'number' => array_merge(["'numeric'"], $this->numericRules($value)),
            'boolean' => ["'boolean'"],
            'array' => array_merge(["'array'"], $this->arrayCountRules($value)),
            'object' => ["'array'"],
            default => [],
        };

        return [$rules === [] ? null : $rules, false];
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
            'uri', 'url', 'iri' => "'url'",
            'ipv4' => "'ipv4'",
            'ipv6' => "'ipv6'",
            'ip' => "'ip'",
            'hostname', 'idn-hostname' => "'string'",
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

        foreach (self::REGEX_DELIMITERS as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                return "'regex:".$this->escapeSingleQuoted($candidate.$pattern.$candidate)."'";
            }
        }

        // Every candidate appears in the pattern. Never drop the rule: fall back
        // to a fixed delimiter and backslash-escape its unescaped occurrences so
        // the resulting PCRE stays valid.
        $delimiter = self::REGEX_DELIMITERS[0];
        $escaped = $this->escapeDelimiter($pattern, $delimiter);

        return "'regex:".$this->escapeSingleQuoted($delimiter.$escaped.$delimiter)."'";
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
            return $this->registry[$name]['class'];
        }

        return null;
    }

    private function scalarLiteral(string|int|float $value): string
    {
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
     * @param  list<array{code: string, imports: list<string>}>  $params
     * @param  list<string>  $imports
     * @param  array<string, list<string>>  $rules
     */
    private function renderDataClass(string $className, array $params, array $imports, array $rules, ?string $classDoc = null): string
    {
        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        $docBlock = $classDoc !== null ? "/**\n * ".$classDoc."\n */\n" : '';

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->options->namespace.";\n\n".$useBlock."\n\n".$docBlock.'final class '.$className.' extends Data';

        if ($params === []) {
            return $header."\n{\n}\n";
        }

        $body = implode("\n", array_map(static fn (array $p): string => $p['code'], $params));
        $constructor = "    public function __construct(\n".$body."\n    ) {}";

        return $header."\n{\n".$constructor.$this->renderRules($rules)."\n}\n";
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

        return "\n\n    /**\n     * @return array<string, list<string|object>>\n     */\n    public static function rules(): array\n    {\n        return [\n".implode("\n", $lines)."\n        ];\n    }";
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

            return $allOfNullable ? new ResolvedType($resolved->declaration, true, $resolved->docType, $resolved->imports, $resolved->dataCollectionOf) : $resolved;
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

        $nullable = $this->isNullable($schema);
        $declarations = [];
        $imports = [];

        foreach ($members as $member) {
            // A `{type: null}` member contributes nullability, not a type.
            if ($this->isNullTypeMember($member)) {
                $nullable = true;

                continue;
            }

            if (! $this->isCleanUnionMember($member)) {
                // Any messy member collapses the whole union to mixed. The
                // nullability already gathered still informs the fallback so a
                // nullable union does not silently lose its null.
                return new ResolvedType('mixed', $nullable);
            }

            $resolved = $this->resolveType($member, $nameHint, $depth + 1, $variant);

            if ($resolved->nullable) {
                $nullable = true;
            }

            // Dedupe by PHP type while keeping first-seen (source) order.
            if (! in_array($resolved->declaration, $declarations, true)) {
                $declarations[] = $resolved->declaration;
            }

            foreach ($resolved->imports as $import) {
                $imports[] = $import;
            }
        }

        // Every member was the null type: nothing to union over, stay mixed.
        if ($declarations === []) {
            return new ResolvedType('mixed', $nullable);
        }

        $imports = array_values(array_unique($imports));
        $docType = implode('|', $nullable ? array_merge($declarations, ['null']) : $declarations);

        return new ResolvedType(
            implode('|', $declarations),
            $nullable,
            $docType,
            $imports,
            null,
            true,
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
     * `@var` docType lists the variants.
     *
     * @param  list<string>  $types  non-null type members, in source order
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

    private function resolveReference(Reference $reference): ResolvedType
    {
        $pointer = $reference->getReference();
        $name = $this->refName($pointer);

        if ($name === null) {
            return new ResolvedType('mixed');
        }

        // A reference to a pure-map component inlines the array type at the use
        // site (the component itself has no Data class).
        if (isset($this->mapAliases[$name])) {
            return $this->mapAliases[$name];
        }

        // A reference to a non-object alias component (scalar/array/union)
        // resolves to its underlying type at the use site. Resolution is
        // transitive (alias -> alias) and cycle-guarded via $aliasSeen, so a
        // chain reached mid-resolution still terminates.
        if (isset($this->aliasSchemas[$name])) {
            return $this->resolveAlias($name, $this->aliasSeen);
        }

        if (isset($this->registry[$name]) && is_string($this->registry[$name]['class'])) {
            return new ResolvedType($this->registry[$name]['class']);
        }

        return new ResolvedType('mixed');
    }

    private function resolveArray(Schema $schema, string $nameHint, int $depth, bool $nullable, string $variant = 'all'): ResolvedType
    {
        $items = $schema->items;

        if (! $items instanceof Schema && ! $items instanceof Reference) {
            return new ResolvedType('array', $nullable, 'array<int, mixed>');
        }

        $itemType = $this->resolveType($items, $nameHint.'Item', $depth + 1, $variant);
        $dataCollectionOf = $this->isDataClass($itemType) ? $itemType->declaration : null;

        return new ResolvedType(
            'array',
            $nullable,
            'array<int, '.$itemType->declaration.'>',
            $itemType->imports,
            $dataCollectionOf,
        );
    }

    private function resolveInlineObject(Schema $schema, string $nameHint, int $depth, bool $nullable, string $variant = 'all'): ResolvedType
    {
        $className = $this->names->reserve($this->withSuffix($nameHint));

        // Reserve the slot before recursing so nested cycles cannot reuse it.
        $this->files[$className] = new GeneratedFile($className, '');
        $this->emitData($className, $schema, $depth, $variant);

        return new ResolvedType($className, $nullable);
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

        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->options->namespace.";\n\nenum ".$className.': '.$backing."\n{\n".$body."\n}\n";

        $this->files[$className] = new GeneratedFile($className, $code);
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
     * The usable scalar values of an `enum`: strings, ints, and floats. Floats
     * are included so a `{type: number, enum: [1.5, 2.5]}` schema still emits a
     * `Rule::in([1.5, 2.5])` constraint instead of silently accepting any number.
     * A float-bearing enum cannot become a native backed enum (PHP enums only
     * back int/string), so isBackedEnum() screens floats out separately.
     *
     * @return list<string|int|float>
     */
    private function enumValues(Schema $schema): array
    {
        $result = [];
        foreach ($this->asArray($schema->enum) as $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Whether a component schema should become a native PHP backed enum. It must
     * be an enum with usable values, no named properties, and crucially every
     * value must be int or string: PHP backed enums cannot back a float. A
     * float-bearing enum is therefore NOT a backed enum here; it falls through to
     * a Data class that carries the `Rule::in` constraint instead (see
     * scalarEnumValueProperty / emitData).
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
            if (is_float($value)) {
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

    private function isReadOnly(Schema|Reference $schema): bool
    {
        return $schema instanceof Schema && $schema->readOnly === true;
    }

    private function isWriteOnly(Schema|Reference $schema): bool
    {
        return $schema instanceof Schema && $schema->writeOnly === true;
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
            return new ResolvedType('mixed');
        }

        $schema = $this->aliasSchemas[$name] ?? null;
        if ($schema === null) {
            return new ResolvedType('mixed');
        }

        $seen[$name] = true;
        $this->aliasSeen = $seen;
        $resolved = $this->resolveType($schema, PhpIdentifier::toClassName($name), 0, 'all');
        $this->aliasSeen = [];

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

        return new ResolvedType(
            'array',
            $nullable,
            'array<string, '.$valueType->declaration.'>',
            $valueType->imports,
            null,
            false,
            true,
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
