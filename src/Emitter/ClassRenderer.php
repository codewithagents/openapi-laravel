<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Renders generated PHP source for the Data layer (issue #109, extracted from
 * ModelGenerator): constructor properties with their attributes and defaults,
 * the import list, and the four class shapes (plain Data class, per-operation
 * query class with its fromQuery() factory, discriminated-union base, union
 * variant), including the validation-trait mixing of issue #83. Pure string
 * assembly; namespaces and support imports resolve through the shared
 * {@see GenerationState}.
 *
 * @internal
 */
final class ClassRenderer
{
    public function __construct(
        private readonly GenerationState $state,
    ) {}

    /**
     * The map transformer short name. A map-typed property gets a
     * `#[WithTransformer(MapObjectTransformer::class)]` attribute plus an import
     * of this class, resolved (like the rule classes) against the consumer's own
     * Support namespace so generated output owns it (issue #40).
     */
    private const MAP_TRANSFORMER = 'MapObjectTransformer';

    /**
     * The comment rendered inside an empty Data class body (issue #95). An empty
     * class compiles fine but silently drops every payload field, so the gap is
     * made visible in the generated code itself (a build warning naming the
     * schema accompanies it). The comment also keeps the body non-empty, which
     * keeps Pint's `single_line_empty_body` fixer away from it.
     */
    private const EMPTY_BODY_MARKER = '// The spec defines no properties for this schema.';

    /**
     * @param  list<array{code: string, imports: list<string>}>  $params
     * @param  array<string, list<string>>  $rules
     * @return list<string>
     */
    public function collectImports(array $params, bool $usesRule, array $rules): array
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
        foreach (RulesBuilder::RULE_CLASS_NAMES as $shortName) {
            if (str_contains($ruleText, 'new '.$shortName)) {
                $imports[] = $this->state->supportImport($shortName);
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
    public function renderProperty(string $wireName, string $propertyName, ResolvedType $type, bool $isRequired, ?array $default = null, ?string $deprecationTag = null): array
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
            $imports[] = $this->state->supportImport(self::MAP_TRANSFORMER);
            $lines[] = '        #[WithTransformer(MapObjectTransformer::class)]';
        }

        if (PhpIdentifier::needsMapName($wireName, $propertyName)) {
            $imports[] = 'Spatie\\LaravelData\\Attributes\\MapName';
            $lines[] = "        #[MapName('".PhpLiteral::escapeSingleQuoted($wireName)."')]";
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
     * (it keeps the `= null` default and is still optional). The node's
     * `hasDefault` presence flag is read so an explicit `default: null`/`false`/`0`
     * is distinguished from "no default at all".
     *
     * @return array{0: string}|null
     */
    public function defaultValue(SchemaNode|ReferenceNode $schema, ResolvedType $type): ?array
    {
        if (! $schema instanceof SchemaNode) {
            return null;
        }

        if (! $schema->hasDefault) {
            return null;
        }

        $value = $schema->default;

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

            return $accepts ? [PhpLiteral::numberLiteral($value)] : null;
        }

        if (is_string($value)) {
            return in_array('string', $members, true)
                ? ["'".PhpLiteral::escapeSingleQuoted($value)."'"]
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
            if (! in_array($member, SchemaFacts::SCALARS, true)) {
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
        $trait = $this->state->options->validationTrait;
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
        if ($traitNamespace !== ltrim($this->state->namespaceFor($className), '\\') && ! in_array($fqcn, $imports, true)) {
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
     * @param  list<string>|null  $fromRouteBooleans  non-null emits the route-only fromRoute() factory (per-operation path classes, issue #113); the list holds the wire names of boolean parameters that need true/false literal mapping. At most one of $fromQueryBooleans / $fromRouteBooleans / $fromHeaderNames is non-null.
     * @param  list<string>|null  $fromHeaderNames  non-null emits the header-only fromHeaders() factory (per-operation header classes, issue #121); the list holds EVERY header's lowercased wire name, so the factory pulls the first value of each declared header before validating
     * @param  list<string>  $fromHeaderBooleans  the subset of $fromHeaderNames that are boolean and need true/false literal mapping; ignored unless $fromHeaderNames is non-null
     */
    public function renderDataClass(string $className, array $params, array $imports, array $rules, array $classDoc = [], ?array $fromQueryBooleans = null, ?array $fromRouteBooleans = null, ?array $fromHeaderNames = null, array $fromHeaderBooleans = []): string
    {
        [$imports, $traitUse] = $this->applyValidationTrait($className, $imports);

        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        // One doc line renders the established single-`*`-line block (so existing
        // output stays byte-identical); multiple lines render one ` * ` per line.
        $docBlock = $classDoc === []
            ? ''
            : "/**\n".implode("\n", array_map(static fn (string $line): string => ' * '.$line, $classDoc))."\n */\n";

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->state->namespaceFor($className).";\n\n".$useBlock."\n\n".$docBlock.'final class '.$className.' extends Data';

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

        $factory = match (true) {
            $fromQueryBooleans !== null => "\n\n".$this->renderFromQuery($fromQueryBooleans),
            $fromRouteBooleans !== null => "\n\n".$this->renderFromRoute($fromRouteBooleans),
            $fromHeaderNames !== null => "\n\n".$this->renderFromHeaders($fromHeaderNames, $fromHeaderBooleans),
            default => '',
        };

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
            fn (string $name): string => "'".PhpLiteral::escapeSingleQuoted($name)."'",
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
     * The route-only creation factory every per-operation path Data class
     * carries (issue #113). It validates against rules() and hydrates from the
     * request's resolved ROUTE parameters only (`$request->route()->parameters()`),
     * so path constraints (min/max/pattern/enum/format) declared in the spec
     * are enforced at runtime instead of silently dropped: a bad path value is
     * a 422, not a 200. Unlike the query class this is NOT auto-injected: the
     * positional scalar path arguments already occupy the controller signature,
     * so the class is a separate, additive validation seam the implementer
     * calls explicitly.
     *
     * Boolean parameters get the same true/false literal mapping fromQuery()
     * applies, so a `{flag}` path segment carrying the form-style literals
     * validates and hydrates correctly. Path booleans are rare, but the
     * machinery is shared with the query path for parity.
     *
     * @param  list<string>  $booleanNames  wire names of the class's boolean parameters, in spec order
     */
    private function renderFromRoute(array $booleanNames): string
    {
        $doc = '    /**'."\n"
            .'     * Validate against rules() and hydrate from the resolved route parameters'."\n"
            .'     * only, so path-segment constraints are enforced at runtime (a bad value'."\n"
            .'     * is a 422, not a silent 200).'."\n";

        if ($booleanNames === []) {
            return $doc
                .'     */'."\n"
                .'    public static function fromRoute(Request $request): static'."\n"
                ."    {\n"
                .'        return self::validateAndCreate($request->route()->parameters());'."\n"
                .'    }';
        }

        $names = implode(', ', array_map(
            fn (string $name): string => "'".PhpLiteral::escapeSingleQuoted($name)."'",
            $booleanNames,
        ));

        return $doc
            .'     * Boolean parameters arrive as the form-style literals true / false,'."\n"
            .'     * which are mapped to 1 / 0 before validation.'."\n"
            .'     */'."\n"
            .'    public static function fromRoute(Request $request): static'."\n"
            ."    {\n"
            .'        $parameters = $request->route()->parameters();'."\n"
            ."\n"
            .'        foreach (['.$names.'] as $name) {'."\n"
            .'            if (array_key_exists($name, $parameters)) {'."\n"
            .'                $parameters[$name] = match ($parameters[$name]) {'."\n"
            ."                    'true' => '1',"."\n"
            ."                    'false' => '0',"."\n"
            .'                    default => $parameters[$name],'."\n"
            .'                };'."\n"
            .'            }'."\n"
            .'        }'."\n"
            ."\n"
            .'        return self::validateAndCreate($parameters);'."\n"
            .'    }';
    }

    /**
     * The header-only creation factory every per-operation header Data class
     * carries (issue #121). It validates against rules() and hydrates from the
     * request HEADERS only, so a constrained custom header (min/max/pattern/
     * enum/format) is enforced at runtime instead of silently dropped: a bad
     * value is a 422, not a 200. Like the path class it is NOT auto-injected
     * (it would otherwise shadow a body/query container resolution), so the
     * implementer calls it explicitly.
     *
     * Two header-specific wire facts shape the factory: Symfony lowercases
     * every key in `$request->headers->all()` and returns each value as an
     * array-of-strings, so the factory pulls the FIRST value of each declared
     * (lowercased) header into a flat map keyed by the same lowercased names
     * the rules() use. Absent headers are simply not added, so a missing
     * REQUIRED header is rejected by its rule and an optional one stays unset.
     * Boolean headers get the same true/false literal mapping fromQuery()
     * applies.
     *
     * @param  list<string>  $headerNames  every header's lowercased wire name, in spec order
     * @param  list<string>  $booleanNames  the subset of $headerNames that are boolean
     */
    private function renderFromHeaders(array $headerNames, array $booleanNames): string
    {
        $names = implode(', ', array_map(
            fn (string $name): string => "'".PhpLiteral::escapeSingleQuoted($name)."'",
            $headerNames,
        ));

        $doc = '    /**'."\n"
            .'     * Validate against rules() and hydrate from the request headers only, so'."\n"
            .'     * a constrained custom header is enforced at runtime (a bad value is a 422,'."\n"
            .'     * not a silent 200). HTTP header names are case-insensitive (read'."\n"
            .'     * lowercased) and each value is an array, so the first value of each'."\n"
            .'     * declared header is taken before validation.'."\n";

        // The shared extraction prologue: pull the first value of each declared
        // header (lowercased keys, array values) into a flat map.
        $prologue = '        $all = $request->headers->all();'."\n"
            .'        $headers = [];'."\n"
            ."\n"
            .'        foreach (['.$names.'] as $name) {'."\n"
            .'            if (isset($all[$name]) && $all[$name] !== []) {'."\n"
            .'                $headers[$name] = $all[$name][0];'."\n"
            .'            }'."\n"
            .'        }'."\n";

        if ($booleanNames === []) {
            return $doc
                .'     */'."\n"
                .'    public static function fromHeaders(Request $request): static'."\n"
                ."    {\n"
                .$prologue
                ."\n"
                .'        return self::validateAndCreate($headers);'."\n"
                .'    }';
        }

        $boolNames = implode(', ', array_map(
            fn (string $name): string => "'".PhpLiteral::escapeSingleQuoted($name)."'",
            $booleanNames,
        ));

        return $doc
            .'     * Boolean parameters arrive as the form-style literals true / false,'."\n"
            .'     * which are mapped to 1 / 0 before validation.'."\n"
            .'     */'."\n"
            .'    public static function fromHeaders(Request $request): static'."\n"
            ."    {\n"
            .$prologue
            ."\n"
            .'        foreach (['.$boolNames.'] as $name) {'."\n"
            .'            if (array_key_exists($name, $headers)) {'."\n"
            .'                $headers[$name] = match ($headers[$name]) {'."\n"
            ."                    'true' => '1',"."\n"
            ."                    'false' => '0',"."\n"
            .'                    default => $headers[$name],'."\n"
            .'                };'."\n"
            .'            }'."\n"
            .'        }'."\n"
            ."\n"
            .'        return self::validateAndCreate($headers);'."\n"
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
    public function renderDiscriminatorBase(string $className, array $imports, string $mapName, string $propertyName, ResolvedType $type, array $validationAttributes, array $arms, ?string $deprecationTag = null): string
    {
        [$imports, $traitUse] = $this->applyValidationTrait($className, $imports);

        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        $docBlock = $deprecationTag !== null ? '/**'."\n".' * '.$deprecationTag."\n".' */'."\n" : '';

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->state->namespaceFor($className).";\n\n".$useBlock."\n\n".$docBlock
            .'abstract class '.$className.' extends Data implements PropertyMorphableData';

        $attributeLine = '        #[PropertyForMorph, '.implode(', ', $validationAttributes)."]\n";

        // The discriminator is declared NULLABLE with a `null` default even though
        // the spec marks it required (#124 follow-up). The default is the lever
        // that makes a MISSING discriminator reach morph(): spatie's
        // DataMorphClassResolver short-circuits to a null morph (which the creation
        // paths turn into the uncatchable CannotCreateAbstractClass, a 500) when
        // the morphable property is absent AND has NO default value; giving it a
        // default makes the resolver call morph() with that default, so the default
        // arm throws a ValidationException (a clean 422) instead. `null` is the
        // sentinel because no OpenAPI discriminator mapping value is ever null, so
        // it can never collide with a real variant arm (true for an int
        // discriminator too, where any non-null literal default could be a real
        // mapping key). The `Required` attribute keeps the spec-required contract,
        // and a valid payload still hydrates the variant with its real value, since
        // the variants forward a non-null value into this nullable parameter.
        $nullableType = $type->nullable || $type->declaration === 'mixed'
            ? $type->declaration()
            : '?'.$type->declaration();

        $constructor = "    public function __construct(\n"
            .$mapName
            .$attributeLine
            .'        public readonly '.$nullableType.' $'.$propertyName.' = null,'."\n"
            .'    ) {}';

        // spatie's DataMorphClassResolver builds the morph() input keyed by the
        // PHP PROPERTY name (DataProperty->name), not the wire name, even when a
        // #[MapName] remaps the input. So match on the property name: a
        // discriminator that needs a MapName (pet_type -> petType) would otherwise
        // read a missing key and always morph to null, rejecting every payload.
        $morph = "\n\n    /**\n     * @param  array<string, mixed>  \$properties\n     */\n    public static function morph(array \$properties): ?string\n    {\n"
            .'        return match ($properties['."'".PhpLiteral::escapeSingleQuoted($propertyName)."'".'] ?? null) {'."\n"
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
    public function renderVariantClass(string $className, string $baseClass, string $discriminatorProperty, string $discriminatorDeclaration, array $params, array $imports, array $rules, ?string $deprecationTag = null): string
    {
        [$imports, $traitUse] = $this->applyValidationTrait($className, $imports);

        // The base lives in the same namespace, so a variant can have zero
        // imports; emit no `use` block then (avoid stray blank lines).
        $useBlock = $imports === []
            ? ''
            : implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports))."\n\n";

        $docBlock = $deprecationTag !== null ? '/**'."\n".' * '.$deprecationTag."\n".' */'."\n" : '';

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->state->namespaceFor($className).";\n\n".$useBlock.$docBlock
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
            $lines[] = "            '".PhpLiteral::escapeSingleQuoted((string) $key)."' => [".implode(', ', $expressions).'],';
        }

        // The key type is `array-key`, not `string`: a property whose JSON name is
        // a numeric string (e.g. GitHub reaction counts keyed `+1`, `-1`) becomes
        // an int array key in PHP, so PHPStan infers an `int|string`-keyed array
        // and a `string`-keyed return type would not match it. `array-key` covers
        // both without widening the value type.
        return "\n\n    /**\n     * @return array<array-key, list<string|object>>\n     */\n    public static function rules(): array\n    {\n        return [\n".implode("\n", $lines)."\n        ];\n    }";
    }
}
