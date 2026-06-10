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
        $this->files = [];

        $schemas = $this->componentSchemas($document);
        ksort($schemas);

        foreach ($schemas as $name => $schema) {
            $kind = $this->isEnum($schema) ? 'enum' : 'data';
            $base = PhpIdentifier::toClassName($name);
            $class = $kind === 'data' ? $this->withSuffix($base) : $base;
            $class = $this->names->reserve($class);

            $this->registry[$name] = ['class' => $class, 'kind' => $kind, 'schema' => $schema];
        }

        foreach ($this->registry as $entry) {
            if ($entry['kind'] === 'enum') {
                $this->emitEnum($entry['class'], $entry['schema']);
            } elseif ($this->hasReadWriteFlags($entry['schema'])) {
                // The spec marks fields readOnly/writeOnly: split into a read
                // variant (drops writeOnly) and a write variant (drops readOnly).
                $this->emitData($entry['class'], $entry['schema'], 0, 'read');
                $writeClass = $this->names->reserve($this->withSuffix($this->stripSuffix($entry['class']).'Writable'));
                $this->emitData($writeClass, $entry['schema'], 0, 'write');
            } else {
                $this->emitData($entry['class'], $entry['schema'], 0, 'all');
            }
        }

        ksort($this->files);

        return $this->files;
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
            $isRequired = in_array($wireName, $required, true);
            $type = $this->resolveType($propertySchema, $base.PhpIdentifier::toClassName($wireName), $depth + 1, $variant);

            $rendered = $this->renderProperty($wireName, $propertyName, $type, $isRequired);

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
        $imports = $this->collectImports($params, $usesRule);

        $this->files[$className] = new GeneratedFile(
            $className,
            $this->renderDataClass($className, $params, $imports, $rules),
        );
    }

    /**
     * @param  list<array{code: string, imports: list<string>}>  $params
     * @return list<string>
     */
    private function collectImports(array $params, bool $usesRule): array
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

        $imports = array_values(array_unique($imports));
        sort($imports);

        return $imports;
    }

    /**
     * @return array{code: string, imports: list<string>}
     */
    private function renderProperty(string $wireName, string $propertyName, ResolvedType $type, bool $isRequired): array
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

        if (PhpIdentifier::needsMapName($wireName, $propertyName)) {
            $imports[] = 'Spatie\\LaravelData\\Attributes\\MapName';
            $lines[] = "        #[MapName('".$this->escapeSingleQuoted($wireName)."')]";
        }

        $declaration = $isRequired ? $type->declaration() : $this->optionalDeclaration($type);
        $default = $isRequired ? '' : ' = null';

        $lines[] = '        public readonly '.$declaration.' $'.$propertyName.$default.',';

        return ['code' => implode("\n", $lines), 'imports' => array_values($imports)];
    }

    private function optionalDeclaration(ResolvedType $type): string
    {
        if ($type->declaration === 'mixed') {
            return 'mixed';
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
            $enumClass = $this->referencedEnumClass($schema);
            if ($enumClass !== null) {
                $rules[] = 'Rule::enum('.$enumClass.'::class)';

                return [$rules, null, true];
            }

            return [$rules, null, false];
        }

        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf) || $this->notEmptyArray($schema->allOf)) {
            return [$rules, null, false];
        }

        if ($this->notEmptyArray($schema->enum)) {
            $values = $this->enumValues($schema);
            if ($values !== []) {
                $rules[] = 'Rule::in(['.implode(', ', array_map(fn (string|int $value): string => $this->scalarLiteral($value), $values)).'])';

                return [$rules, null, true];
            }
        }

        $primary = $this->normalizeTypes($schema)[0] ?? null;

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
                return [['Rule::in(['.implode(', ', array_map(fn (string|int $value): string => $this->scalarLiteral($value), $values)).'])'], true];
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
     * @return list<string>
     */
    private function numericRules(Schema $schema): array
    {
        $rules = [];

        $min = $schema->minimum;
        if (is_int($min) || is_float($min)) {
            $rules[] = "'min:".$this->numberLiteral($min)."'";
        }

        $max = $schema->maximum;
        if (is_int($max) || is_float($max)) {
            $rules[] = "'max:".$this->numberLiteral($max)."'";
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
            'date' => "'date'",
            'date-time' => "'date'",
            'uri', 'url', 'iri' => "'url'",
            'ipv4' => "'ipv4'",
            'ipv6' => "'ipv6'",
            'ip' => "'ip'",
            'hostname', 'idn-hostname' => "'string'",
            default => null,
        };
    }

    private function regexRule(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }

        $delimiter = ! str_contains($pattern, '#') ? '#' : (! str_contains($pattern, '~') ? '~' : null);

        if ($delimiter === null) {
            return null;
        }

        return "'regex:".$this->escapeSingleQuoted($delimiter.$pattern.$delimiter)."'";
    }

    private function referencedEnumClass(Reference $reference): ?string
    {
        $name = $this->refName($reference->getReference());

        if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'enum') {
            return $this->registry[$name]['class'];
        }

        return null;
    }

    private function scalarLiteral(string|int $value): string
    {
        return is_int($value) ? (string) $value : "'".$this->escapeSingleQuoted($value)."'";
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
    private function renderDataClass(string $className, array $params, array $imports, array $rules): string
    {
        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->options->namespace.";\n\n".$useBlock."\n\nfinal class ".$className.' extends Data';

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

        return "\n\n    public static function rules(): array\n    {\n        return [\n".implode("\n", $lines)."\n        ];\n    }";
    }

    private function resolveType(Schema|Reference $schema, string $nameHint, int $depth, string $variant = 'all'): ResolvedType
    {
        if ($depth > $this->options->maxDepth) {
            throw new GenerationException("Maximum schema depth ({$this->options->maxDepth}) exceeded at {$nameHint}.");
        }

        if ($schema instanceof Reference) {
            return $this->resolveReference($schema);
        }

        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf) || $this->notEmptyArray($schema->allOf)) {
            return new ResolvedType('mixed', $this->isNullable($schema));
        }

        $types = $this->normalizeTypes($schema);
        $nullable = $this->isNullable($schema);
        $primary = $types[0] ?? null;

        if ($primary !== null && isset(self::SCALARS[$primary])) {
            return new ResolvedType(self::SCALARS[$primary], $nullable);
        }

        if ($primary === 'array') {
            return $this->resolveArray($schema, $nameHint, $depth, $nullable, $variant);
        }

        if ($primary === 'object' || ($primary === null && $this->notEmptyArray($schema->properties))) {
            return $this->resolveInlineObject($schema, $nameHint, $depth, $nullable, $variant);
        }

        return new ResolvedType('mixed', $nullable);
    }

    private function resolveReference(Reference $reference): ResolvedType
    {
        $pointer = $reference->getReference();
        $name = $this->refName($pointer);

        if ($name !== null && isset($this->registry[$name])) {
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
        $values = $this->enumValues($schema);
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
     * @return list<string|int>
     */
    private function enumValues(Schema $schema): array
    {
        $result = [];
        foreach ($this->asArray($schema->enum) as $value) {
            if (is_string($value) || is_int($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function isEnum(Schema $schema): bool
    {
        if (! $this->notEmptyArray($schema->enum)) {
            return false;
        }

        if ($this->notEmptyArray($schema->properties)) {
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
     * @return array<string, Schema|Reference>
     */
    private function objectProperties(Schema $schema): array
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
    private function requiredNames(Schema $schema): array
    {
        $result = [];
        foreach ($this->asArray($schema->required) as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return $result;
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
