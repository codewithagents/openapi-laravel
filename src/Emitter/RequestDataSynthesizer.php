<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use Closure;
use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Synthesizes the per-operation request Data classes (issue #109, extracted
 * from ModelGenerator): the query classes of issue #63, the inline JSON
 * request bodies of issue #76, and the multipart/form-data bodies of issue
 * #75, including the shape gates that decide when an operation input cannot
 * be typed soundly (and warn instead). Bodies re-enter the component pipeline
 * through the injected emitData() callback, so a synthesized class is built
 * with exactly the fidelity of a component class.
 *
 * @internal
 */
final class RequestDataSynthesizer
{
    /**
     * The PHP type a multipart file part (issue #75) is hydrated into.
     */
    private const UPLOADED_FILE_FQCN = 'Illuminate\\Http\\UploadedFile';

    /**
     * @param  Closure(string, SchemaNode, int, string, list<string>): void  $emitData  runs the component emission pipeline for a body class
     * @param  Closure(SchemaNode): bool  $hasReadWriteFlags  whether a schema splits into read/write variants
     */
    public function __construct(
        private readonly GenerationState $state,
        private readonly TypeResolver $types,
        private readonly RulesBuilder $rules,
        private readonly ClassRenderer $renderer,
        private readonly Closure $emitData,
        private readonly Closure $hasReadWriteFlags,
    ) {}

    // Deepest array nesting a query parameter may have: bracket query strings beyond a few levels are not a real wire format.
    private const QUERY_MAX_ARRAY_DEPTH = 4;

    /**
     * Component requestBody name => the SHARED Data class synthesized for it
     * (issue #110), so several operations referencing one
     * `#/components/requestBodies/<Name>` entry reuse one class instead of
     * emitting per-operation duplicates. Only successful syntheses are cached:
     * a failed one (non-object shape) warns per referencing operation. Per-run
     * state, like everything else here: the synthesizer is recreated together
     * with the GenerationState on every generate() run. Note: per GENERATOR
     * lifecycle (one generate() call), not per collect() call, so a generator
     * reused across different documents would return a stale class name.
     *
     * @var array<string, string>
     */
    private array $componentBodyClasses = [];

    /**
     * Component response name => the SHARED Data class synthesized for it
     * (issue #116), mirroring $componentBodyClasses: several operations
     * routinely `$ref` one `#/components/responses/<Name>` entry, and the
     * shared shape gets ONE class instead of per-operation duplicates. Only
     * successful syntheses are cached: a failed one (non-object shape) warns
     * per referencing operation. Per-run state, like everything else here.
     * Note: per GENERATOR lifecycle (one generate() call), not per collect()
     * call, so a generator reused across different documents would return a
     * stale class name.
     *
     * @var array<string, string>
     */
    private array $componentResponseClasses = [];

    /**
     * Emit a per-operation query Data class (issue #63) for an operation's
     * `in: query` parameters, reusing the EXACT rules/type pipeline the body
     * Data classes go through (resolveType, buildRules, renderProperty), so a
     * query constraint is enforced with the same fidelity as a body constraint.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` parameters
     * against the run's component registry and alias caches. The collected
     * files are exposed via {@see queryFiles()}; the class name is reserved in
     * the run's allocator so a query class can never collide with a component
     * class (a clash suffixes deterministically, e.g. `..._2`).
     *
     * Each query class also carries a `fromQuery(Request $request)` factory
     * that validates and hydrates from `$request->query()` ONLY. For an
     * operation with a request body this is the supported access path (the
     * class is not injected, or body fields would bleed into query
     * validation); for a body-less operation laravel-data picks `fromQuery`
     * up as the magic creation method on container injection, so the typed
     * controller parameter hydrates through the same query-only path.
     *
     * A parameter whose serialization cannot round-trip through Laravel's
     * query parsing is skipped with a warning rather than given rules that
     * would false-reject valid requests: a flat-form object or object-map
     * shape, and a content-typed parameter. A delimited (non-exploded) array
     * is split in fromQuery() before validating (issue #132). A `deepObject`
     * OBJECT parameter (issue #131) is synthesized as a nested object property:
     * `?filter[gte]=10` parses NATIVELY into a nested array, so it needs no
     * splitting, and the nested Data class carries the per-property rules; a
     * non-object deepObject (or `explode: false`) keeps the skip.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "GET /pets", for warning messages
     * @param  list<ParameterNode>  $parameters  the operation's `in: query` parameters, in spec order
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved query class name, or null when every parameter was skipped
     */
    public function generateQueryData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        return $this->generateParamData('query', $baseName, $operationLabel, $parameters, $tag);
    }

    /**
     * Emit a per-operation path Data class (issue #113) for an operation's
     * `in: path` parameters, reusing the EXACT rules/type pipeline the query
     * and body Data classes go through, so a path constraint
     * (min/max/pattern/enum/format) is enforced at runtime instead of being
     * silently dropped: a bad path value is a 422, not a 200. The class is the
     * runtime validation seam, separate from and additive to the positional
     * scalar path arguments the route still binds into the controller method
     * signature.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` parameters
     * against the run's component registry and alias caches. The collected
     * files are exposed via {@see pathFiles()}; the class name is reserved in
     * the run's allocator so a path class can never collide with a component,
     * query, or body class (a clash suffixes deterministically, e.g. `..._2`).
     *
     * Each path class carries a `fromRoute(Request $request)` factory that
     * validates and hydrates from `$request->route()->parameters()` ONLY. It
     * is never auto-injected (the positional path scalars already fill the
     * signature), so the implementer calls it explicitly; the controller
     * carries a docblock pointer to it.
     *
     * Path parameters are always single scalars or enums per the OpenAPI spec
     * (no styles, no arrays, no objects), so the query skip machinery mostly
     * collapses here; a non-scalar/non-enum schema degrades to a warned `mixed`
     * presence-only property, mirroring how query handles an unknown shape.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "GET /pets/{petId}", for warning messages
     * @param  list<ParameterNode>  $parameters  the operation's `in: path` parameters, in spec order
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved path class name, or null when there are no path parameters
     */
    public function generatePathData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        return $this->generateParamData('path', $baseName, $operationLabel, $parameters, $tag);
    }

    /**
     * Emit a per-operation header Data class (issue #121) for an operation's
     * `in: header` parameters, reusing the EXACT rules/type pipeline the query
     * and path Data classes go through, so a constrained custom header
     * (min/max/pattern/enum/format) is validated at runtime instead of being
     * silently dropped: a bad value is a 422, not a 200.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` parameters
     * against the run's component registry and alias caches. The collected
     * files are exposed via {@see headerFiles()}; the class name is reserved in
     * the run's allocator so a header class can never collide with a component,
     * query, path, or body class (a clash suffixes deterministically).
     *
     * Each header class carries a `fromHeaders(Request $request)` factory that
     * validates and hydrates from the request headers ONLY. It is never
     * auto-injected (it would otherwise shadow a body/query container
     * resolution), so the implementer calls it explicitly; the controller
     * carries a docblock pointer to it.
     *
     * Two header-specific wrinkles handle the wire shape (issue #121): HTTP
     * header names are case-insensitive, so the wire key is the LOWERCASED spec
     * name (matching Symfony's `$request->headers->all()` keys), and each
     * header value is an array-of-strings, so the factory takes the FIRST
     * value of each header before validation. Reserved/framework-owned
     * standard headers (Accept, Content-Type, Authorization, Host, ...) are
     * skipped with a warning so the generator never validates headers the
     * framework manages; see {@see headerSkipReason()}. A non-scalar/non-enum
     * schema degrades to a warned `mixed` presence-only property, like path.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "GET /pets", for warning messages
     * @param  list<ParameterNode>  $parameters  the operation's `in: header` parameters, in spec order
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved header class name, or null when every parameter was skipped
     */
    public function generateHeaderData(string $baseName, string $operationLabel, array $parameters, ?string $tag = null): ?string
    {
        return $this->generateParamData('header', $baseName, $operationLabel, $parameters, $tag);
    }

    /**
     * The shared core behind {@see generateQueryData()} (issue #63),
     * {@see generatePathData()} (issue #113), and {@see generateHeaderData()}
     * (issue #121): synthesize a per-operation Data class from the parameters
     * of ONE `in:` location, through the exact rules/type pipeline a body class
     * uses. Parameterized by `$in`. The callers differ only in the location
     * filter, the class-name suffix, the docblock wording, the factory the
     * class carries (fromQuery / fromRoute / fromHeaders), the wire-name
     * normalization (header lowercases), and the skip machinery: query skips
     * un-serializable shapes (styles, arrays of objects), path has none of
     * those forms and only degrades a non-scalar/non-enum schema to
     * presence-only `mixed`, and header additionally skips the reserved
     * framework-owned standard headers.
     *
     * @param  'query'|'path'|'header'  $in
     * @param  list<ParameterNode>  $parameters
     */
    private function generateParamData(string $in, string $baseName, string $operationLabel, array $parameters, ?string $tag): ?string
    {
        $config = $this->paramLocationConfig($in);

        // Degradation warnings inside the pipeline name the operation, not a
        // schema: the parameters being resolved are operation-owned.
        $this->state->warningContext = sprintf('%s of operation %s', $config['warningContext'], $operationLabel);

        $supported = [];
        foreach ($parameters as $parameter) {
            if ($parameter->in !== $in) {
                continue;
            }

            $reason = ($config['skipReason'])($parameter);

            if ($reason !== null) {
                $name = $parameter->name === '' ? '(unnamed)' : $parameter->name;
                $this->state->warnings[sprintf(
                    'Operation %s: %s parameter "%s" was skipped: %s.',
                    $operationLabel,
                    $in,
                    $name,
                    $reason,
                )] = true;

                continue;
            }

            $supported[] = $parameter;
        }

        if ($supported === []) {
            return null;
        }

        $className = $this->state->names->reserve($this->state->options->withSuffix($baseName.$config['suffix']));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93); in the flat layout the group is null.
        $this->state->fileGroups[$className] = $this->groupForTag($tag);
        $this->state->pushRefScope($className);

        $base = $this->state->options->stripSuffix($className);
        $propertyNames = new UniqueNames;

        $paramsRequired = [];
        $paramsOptional = [];
        $rules = [];
        $usesRule = false;
        $booleanNames = [];
        $headerNames = [];

        // Wire name -> delimiter for delimited (non-exploded) array query
        // parameters (issue #132); the fromQuery() factory splits the single
        // joined string on the delimiter before the array rules validate it.
        $delimitedArrayNames = [];

        // The query path can now spawn nested Data classes (a deepObject object
        // parameter, issue #131), which resolveInlineObject() writes into
        // $this->state->files (the main component bucket already returned by
        // generate()). Mirror the body path's bucket discipline: emit into a
        // clean bucket and drain the spawned classes into $queryFiles for the
        // planner. Path and header never spawn classes (they degrade a
        // non-scalar to presence-only mixed), so the swap is query-only and a
        // no-op for them in practice; keeping it query-scoped leaves their
        // established behavior byte-identical.
        $mainFiles = $this->state->files;
        if ($in === 'query') {
            $this->state->files = [];
        }

        foreach ($supported as $parameter) {
            // HTTP header names are case-insensitive and Symfony lowercases
            // every key in $request->headers->all() (issue #121), so a header
            // parameter's wire key (the #[MapName] AND the rules() key) is the
            // LOWERCASED spec name; the factory reads the same lowercased key.
            // Query and path keep the spec name verbatim.
            $wireName = $in === 'header' ? strtolower($parameter->name) : $parameter->name;
            $schema = $parameter->schema;
            if ($schema === null) {
                // The skip check guarantees a schema; defensive for PHPStan.
                continue;
            }

            if ($in === 'header') {
                $headerNames[] = $wireName;
            }

            // Distinct wire names can collapse to the same identifier
            // (first_name + firstName); suffix collisions like emitData does.
            $propertyName = $propertyNames->reserve(PhpIdentifier::toPropertyName($wireName));
            $type = $this->types->resolveType($schema, $base.PhpIdentifier::toClassName($wireName), 1);
            $this->state->noteClassRef(...$type->classRefs);

            // The form style serializes a boolean as flag=true / flag=false,
            // but Laravel's `boolean` rule only understands 1/0 (and PHP's
            // coercive bool cast would turn the string "false" into TRUE), so
            // the factory maps the literals to '1'/'0' before validating.
            if ($type->declaration === 'bool') {
                $booleanNames[] = $wireName;
            }

            // A delimited (non-exploded) array query parameter arrives as one
            // joined string; the factory splits it on the delimiter before the
            // array rules run (issue #132). Only applies to query.
            if ($in === 'query') {
                $delimiter = $this->queryArrayDelimiter($parameter);
                if ($delimiter !== null) {
                    $delimitedArrayNames[$wireName] = $delimiter;
                }
            }

            // A scalar `default` makes the parameter optional on input even when
            // the spec marks it required, exactly like a defaulted body property:
            // an omitted value is filled by the default.
            $default = $this->renderer->defaultValue($schema, $type);
            $isRequired = $parameter->required === true && $default === null;

            // A parameter can be deprecated on the Parameter object itself or on
            // its schema; either way the property carries the `@deprecated` tag.
            $deprecationTag = $parameter->deprecated === true ? '@deprecated' : SchemaFacts::deprecationTag($schema);

            $rendered = $this->renderer->renderProperty($wireName, $propertyName, $type, $isRequired, $default, $deprecationTag);

            if ($isRequired) {
                $paramsRequired[] = $rendered;
            } else {
                $paramsOptional[] = $rendered;
            }

            [$propertyRules, $wildcardRules, $uses] = $this->rules->buildRules($schema, $isRequired, $type);
            $rules[$wireName] = $propertyRules;
            foreach ($wildcardRules as $suffix => $ruleList) {
                if ($ruleList !== []) {
                    $rules[$wireName.$suffix] = $ruleList;
                }
            }
            $usesRule = $usesRule || $uses;
        }

        $params = array_merge($paramsRequired, $paramsOptional);

        if ($params === []) {
            // Every surviving parameter lacked a usable schema (defensive; the
            // skip check above already filtered these). No useful class to emit.
            // Restore the component bucket (the query swap is the only mutation;
            // any spawned classes are discarded with the abandoned class).
            $this->state->files = $mainFiles;
            $this->state->popRefScope();

            return null;
        }

        // The fromQuery / fromRoute / fromHeaders factory references Request by short name.
        $imports = $this->renderer->collectImports($params, $usesRule, $rules);
        $imports[] = 'Illuminate\\Http\\Request';
        $imports = array_values(array_unique($imports));
        sort($imports);

        // A referenced enum or Data class may live in another tag group
        // (issue #93); import those from their real namespaces.
        $imports = $this->state->withCrossGroupImports($className, $imports, $this->state->popRefScope());

        $classDoc = [
            $config['docPrefix'].' of '.PhpLiteral::docblockSafe($operationLabel).'.',
        ];

        $rendered = match ($in) {
            'path' => $this->renderer->renderDataClass($className, $params, $imports, $rules, $classDoc, fromRouteBooleans: $booleanNames),
            'header' => $this->renderer->renderDataClass($className, $params, $imports, $rules, $classDoc, fromHeaderNames: $headerNames, fromHeaderBooleans: $booleanNames),
            default => $this->renderer->renderDataClass($className, $params, $imports, $rules, $classDoc, fromQueryBooleans: $booleanNames, fromQueryDelimitedArrays: $delimitedArrayNames),
        };

        $file = new GeneratedFile(
            $className,
            $rendered,
            $this->state->fileGroups[$className] ?? null,
        );

        match ($in) {
            'path' => $this->state->pathFiles[$className] = $file,
            'header' => $this->state->headerFiles[$className] = $file,
            default => $this->state->queryFiles[$className] = $file,
        };

        if ($in === 'query') {
            // Drain any nested Data classes a deepObject object parameter spawned
            // (issue #131) into $queryFiles for the planner, then restore the
            // component bucket. The main class was just written into $queryFiles
            // directly above, so only the spawned nested classes remain here.
            foreach ($this->state->files as $name => $nestedFile) {
                $this->state->queryFiles[$name] = $nestedFile;
            }
            $this->state->files = $mainFiles;
        }

        // A query class carrying a delimited-array param must be additive, not
        // injected (issue #132): record it so the collector skips injection and
        // points at ::fromQuery($request) instead, the same as path/header.
        //
        // A deepObject parameter (issue #131) does NOT need this: PHP/Laravel
        // parse ?filter[gte]=10 NATIVELY into a nested array, so the value is
        // already in the shape the nested Data class rules expect. The raw
        // request that spatie validates on container injection (body-less GET)
        // therefore validates correctly with no pre-split, unlike the joined
        // delimited string. So a deepObject-only query class stays injectable;
        // only a delimited-array param forces the class additive.
        if ($in === 'query' && $delimitedArrayNames !== []) {
            $this->state->delimitedQueryClasses[$className] = true;
        }

        return $className;
    }

    /**
     * The per-location knobs the shared {@see generateParamData()} core needs:
     * the class-name suffix, the class docblock prefix, the warning-context
     * label, and the skip-reason callback. Keeping them here (rather than in
     * the core) keeps the core location-agnostic; the header arm (issue #121)
     * is the third location, alongside query (#63) and path (#113).
     *
     * @param  'query'|'path'|'header'  $in
     * @return array{suffix: string, docPrefix: string, warningContext: string, skipReason: Closure(ParameterNode): ?string}
     */
    private function paramLocationConfig(string $in): array
    {
        if ($in === 'path') {
            return [
                'suffix' => 'Path',
                'docPrefix' => 'Path parameters',
                'warningContext' => 'Path parameters',
                'skipReason' => fn (ParameterNode $parameter): ?string => $this->pathSkipReason($parameter),
            ];
        }

        if ($in === 'header') {
            return [
                'suffix' => 'Header',
                'docPrefix' => 'Header parameters',
                'warningContext' => 'Header parameters',
                'skipReason' => fn (ParameterNode $parameter): ?string => $this->headerSkipReason($parameter),
            ];
        }

        return [
            'suffix' => 'Query',
            'docPrefix' => 'Query parameters',
            'warningContext' => 'Query parameters',
            'skipReason' => fn (ParameterNode $parameter): ?string => $this->querySkipReason($parameter),
        ];
    }

    /**
     * Standard HTTP headers the framework owns or that carry transport
     * semantics the generator must not validate (issue #121), matched
     * case-insensitively (stored lowercased). Validating these would either
     * collide with Laravel's own handling (Content-Type negotiation, auth
     * middleware, host routing) or generate rules for headers the client never
     * sets through the spec parameter. Only spec-declared CUSTOM headers get a
     * generated rule; a declared reserved header is skipped with a warning so
     * the drop is visible at generation time.
     *
     * @var array<string, true>
     */
    private const RESERVED_HEADER_NAMES = [
        'accept' => true,
        'accept-charset' => true,
        'accept-encoding' => true,
        'accept-language' => true,
        'authorization' => true,
        'cache-control' => true,
        'connection' => true,
        'content-disposition' => true,
        'content-encoding' => true,
        'content-language' => true,
        'content-length' => true,
        'content-type' => true,
        'cookie' => true,
        'date' => true,
        'expect' => true,
        'forwarded' => true,
        'from' => true,
        'host' => true,
        'origin' => true,
        'proxy-authorization' => true,
        'range' => true,
        'referer' => true,
        'te' => true,
        'trailer' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
        'user-agent' => true,
        'via' => true,
    ];

    /**
     * Why a header parameter cannot become a typed, validated property, or null
     * when it can (issue #121). Like the path bar, a header parameter has no
     * `style`/`explode` serialization complexity (the OpenAPI default `simple`
     * style is a single comma-joined or scalar value), so the only hard skips
     * are a missing name, a missing schema, and a reserved/framework-owned
     * standard header (Accept, Content-Type, Authorization, ...). A scalar or
     * enum becomes a fully validated property; a non-scalar/non-enum schema
     * degrades to a `mixed` presence-only property through the body pipeline,
     * mirroring how path handles an unusual shape.
     */
    private function headerSkipReason(ParameterNode $parameter): ?string
    {
        if ($parameter->name === '') {
            return 'it has no usable name';
        }

        if (isset(self::RESERVED_HEADER_NAMES[strtolower($parameter->name)])) {
            return 'it is a reserved, framework-owned standard header (the framework manages it; only custom headers are validated)';
        }

        if ($parameter->schema === null) {
            return 'it declares no schema (content-typed header parameters are not supported yet)';
        }

        return null;
    }

    /**
     * Why a path parameter cannot become a typed, validated property, or null
     * when it can. The bar is far looser than the query bar (issue #113): a
     * path parameter has no `style`/`explode` serialization forms and is a
     * single path segment, so the only hard skip is a missing name. Anything
     * with a schema is kept: a scalar or enum becomes a fully validated
     * property, and a non-scalar/non-enum schema (an object or array, which is
     * unusual but legal in the spec) degrades through the body pipeline to a
     * `mixed` presence-only property, mirroring how a query parameter degrades
     * an unknown component to presence-only `mixed`.
     */
    private function pathSkipReason(ParameterNode $parameter): ?string
    {
        if ($parameter->name === '') {
            return 'it has no usable name';
        }

        if ($parameter->schema === null) {
            return 'it declares no schema (content-typed path parameters are not supported yet)';
        }

        return null;
    }

    /**
     * Emit a per-operation request-body Data class (issue #76) for an inline
     * JSON request-body schema, reusing the EXACT emission pipeline the
     * component Data classes go through (emitData: properties, rules(), nested
     * inline classes, closed-object enforcement, defaults), so an inline body
     * is validated and hydrated with the same fidelity as a `$ref` body.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` properties
     * against the run's component registry and alias caches. The collected
     * files (the body class plus any nested classes it spawned) are exposed via
     * {@see bodyFiles()}; the class name is reserved in the run's allocator so
     * a body class can never collide with a component class or a query class
     * (a clash suffixes deterministically, e.g. `..._2`).
     *
     * Only an OBJECT schema synthesizes a class, mirroring the `$ref` path: a
     * `$ref` body is typed only when it points at a generated Data class, and a
     * non-object component (a scalar/array/union alias, a pure map, an enum)
     * falls back to `Illuminate\Http\Request` there too. A non-object inline
     * schema therefore returns null with a warning, and the caller keeps the
     * established Request fallback.
     *
     * A schema that splits fields with readOnly/writeOnly emits the WRITE shape
     * (readOnly properties dropped), the same variant a component `$ref` body
     * is typed against.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "POST /pets", for warning messages
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class (and its nested classes) in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved body class name, or null when the schema cannot type a body
     */
    public function generateBodyData(string $baseName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        // Degradation warnings inside the body pipeline name the operation,
        // not a schema: an inline body schema is operation-owned.
        $this->state->warningContext = sprintf('Request body of operation %s', $operationLabel);

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->state->warnings[sprintf(
                'Operation %s: the inline request body schema was not generated as a typed Data class (%s); the controller method falls back to Illuminate\Http\Request.',
                $operationLabel,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->state->names->reserve($this->state->options->withSuffix($baseName.'Request'));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93); nested classes it spawns follow it through the
        // emission scope. Null in the flat layout.
        $this->state->fileGroups[$className] = $this->groupForTag($tag);

        // emitData() writes the class (and every nested class it spawns) into
        // $files, which generate() already returned to its caller. Emit into a
        // clean bucket and collect the run's output into $bodyFiles, so the
        // planner picks the body classes up via bodyFiles() exactly like the
        // query classes.
        $mainFiles = $this->state->files;
        $this->state->files = [];

        $variant = ($this->hasReadWriteFlags)($schema) ? 'write' : 'all';
        ($this->emitData)($className, $schema, 0, $variant, ['Request body of '.PhpLiteral::docblockSafe($operationLabel).'.']);

        foreach ($this->state->files as $name => $file) {
            $this->state->bodyFiles[$name] = $file;
        }
        $this->state->files = $mainFiles;

        return $className;
    }

    /**
     * Emit a per-operation request-body Data class for a multipart/form-data
     * body (issue #75), through the exact emitData pipeline the JSON bodies of
     * issue #76 use, with ONE multipart-specific twist: a root property that is
     * a `type: string, format: binary` schema (or an array of binary items) is
     * an uploaded file part, typed `UploadedFile` (or `array<int, UploadedFile>`)
     * with Laravel's `file` rule plus a `mimetypes:` constraint when the part
     * pins its media type via `contentMediaType`. Every non-binary part is
     * validated exactly like a JSON body field.
     *
     * Unlike the JSON path, a schema-level `$ref` body is NOT typed against the
     * component's class: that class was emitted with JSON semantics, where a
     * binary string property is a plain string whose `string` rule would
     * false-reject every actual upload. Instead the referenced component schema
     * is re-emitted as a per-operation class with multipart semantics. A `$ref`
     * that does not resolve to an object component, and any non-object shape,
     * keeps the documented Request fallback with a warning, mirroring
     * {@see generateBodyData()}.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "POST /pets", for warning messages
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class (and its nested classes) in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved body class name, or null when the schema cannot type a body
     */
    public function generateMultipartBodyData(string $baseName, string $operationLabel, SchemaNode|ReferenceNode $schema, ?string $tag = null): ?string
    {
        $this->state->warningContext = sprintf('Multipart request body of operation %s', $operationLabel);

        if ($schema instanceof ReferenceNode) {
            $name = SchemaPointer::refName($schema->pointer());
            if ($name === null || ! isset($this->state->registry[$name]) || $this->state->registry[$name]['kind'] !== 'data') {
                $this->state->warnings[sprintf(
                    'Operation %s: the multipart/form-data request body $ref "%s" does not resolve to an object component schema; the controller method falls back to Illuminate\Http\Request.',
                    $operationLabel,
                    $schema->pointer(),
                )] = true;

                return null;
            }

            $schema = $this->state->registry[$name]['schema'];
        }

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->state->warnings[sprintf(
                'Operation %s: the multipart/form-data request body schema was not generated as a typed Data class (%s); the controller method falls back to Illuminate\Http\Request.',
                $operationLabel,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->state->names->reserve($this->state->options->withSuffix($baseName.'Request'));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93), exactly like generateBodyData(); nested classes
        // it spawns follow it through the emission scope. Null when flat.
        $this->state->fileGroups[$className] = $this->groupForTag($tag);

        // Same bucket discipline as generateBodyData(): emit into a clean
        // bucket and collect into $bodyFiles for the planner.
        $mainFiles = $this->state->files;
        $this->state->files = [];
        $this->state->multipartBody = true;

        $variant = ($this->hasReadWriteFlags)($schema) ? 'write' : 'all';
        ($this->emitData)($className, $schema, 0, $variant, ['Multipart request body of '.PhpLiteral::docblockSafe($operationLabel).'.']);

        $this->state->multipartBody = false;
        foreach ($this->state->files as $name => $file) {
            $this->state->bodyFiles[$name] = $file;
        }
        $this->state->files = $mainFiles;

        return $className;
    }

    /**
     * Emit the SHARED Data class of a component request body (issue #110)
     * whose JSON content schema is an inline object. Several operations
     * routinely `$ref` the same `#/components/requestBodies/<Name>` entry; the
     * shared shape gets ONE class named after the component
     * (`<Component>RequestData`) instead of N per-operation duplicates, so the
     * first referencing operation synthesizes it and every later one reuses
     * the cached name. The synthesis itself is the exact generateBodyData()
     * pipeline (emitData: properties, rules(), nested classes, read/write
     * split), only the name, the class docblock, and the warning wording are
     * component-grained.
     *
     * Only a SUCCESSFUL synthesis is cached: a non-object shape returns null
     * with a warning naming the operation AND the component, and the next
     * referencing operation warns again (each fallback is per-operation
     * information the user should see).
     *
     * @param  string  $componentName  the raw `components.requestBodies` key
     * @param  string  $operationLabel  "POST /pets", for warning messages
     * @param  ?string  $tag  the single tag shared by every operation referencing this component, or null (mixed tag groups or tagless), which places the shared class at the flat root, mirroring how multi-group schemas stay at the root (issue #93)
     * @return string|null the shared body class name, or null when the schema cannot type a body
     */
    public function generateComponentBodyData(string $componentName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        $existing = $this->componentBodyClasses[$componentName] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        // Degradation warnings inside the emission name the component, not
        // whichever operation happened to trigger the synthesis first: the
        // class is shared, so the finding is component-grained.
        $this->state->warningContext = sprintf('Request body component "%s"', $componentName);

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->state->warnings[sprintf(
                'Operation %s: the request body component "%s" was not generated as a typed Data class (%s); the controller method falls back to Illuminate\Http\Request.',
                $operationLabel,
                $componentName,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->state->names->reserve(
            $this->state->options->withSuffix(PhpIdentifier::toClassName($componentName).'Request'),
        );
        $this->state->fileGroups[$className] = $this->groupForTag($tag);

        // Same bucket discipline as generateBodyData(): emit into a clean
        // bucket and collect into $bodyFiles for the planner.
        $mainFiles = $this->state->files;
        $this->state->files = [];

        $variant = ($this->hasReadWriteFlags)($schema) ? 'write' : 'all';
        ($this->emitData)($className, $schema, 0, $variant, ['Request body component "'.PhpLiteral::docblockSafe($componentName).'".']);

        foreach ($this->state->files as $name => $file) {
            $this->state->bodyFiles[$name] = $file;
        }
        $this->state->files = $mainFiles;

        return $this->componentBodyClasses[$componentName] = $className;
    }

    /**
     * Emit the SHARED Data class of a component response (issue #116) whose
     * JSON content schema is an inline object, mirroring
     * {@see generateComponentBodyData()} exactly with ONE deliberate
     * difference: a response is server OUTPUT, so a schema that splits fields
     * with readOnly/writeOnly emits the READ shape (writeOnly properties
     * dropped, readOnly kept), the opposite of the write variant a request
     * body takes. The shared shape gets ONE class named after the component
     * (`<Component>ResponseData`); the first referencing operation
     * synthesizes it and every later one reuses the cached name.
     *
     * Only a SUCCESSFUL synthesis is cached: a non-object shape returns null
     * with a warning naming the operation AND the component, and the next
     * referencing operation warns again (each fallback is per-operation
     * information the user should see).
     *
     * @param  string  $componentName  the raw `components.responses` key
     * @param  string  $operationLabel  "GET /pets", for warning messages
     * @param  ?string  $tag  the single tag shared by every operation referencing this component, or null (mixed tag groups or tagless), which places the shared class at the flat root, mirroring how multi-group schemas stay at the root (issue #93)
     * @return string|null the shared response class name, or null when the schema cannot type a response
     */
    public function generateComponentResponseData(string $componentName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        $existing = $this->componentResponseClasses[$componentName] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        // Degradation warnings inside the emission name the component, not
        // whichever operation happened to trigger the synthesis first: the
        // class is shared, so the finding is component-grained.
        $this->state->warningContext = sprintf('Response component "%s"', $componentName);

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->state->warnings[sprintf(
                'Operation %s: the response component "%s" was not generated as a typed Data class (%s); the return type falls back to JsonResponse.',
                $operationLabel,
                $componentName,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->state->names->reserve(
            $this->state->options->withSuffix(PhpIdentifier::toClassName($componentName).'Response'),
        );
        $this->state->fileGroups[$className] = $this->groupForTag($tag);

        // Same bucket discipline as generateComponentBodyData(): emit into a
        // clean bucket and collect into $responseFiles for the planner.
        $mainFiles = $this->state->files;
        $this->state->files = [];

        $variant = ($this->hasReadWriteFlags)($schema) ? 'read' : 'all';
        ($this->emitData)($className, $schema, 0, $variant, ['Response component "'.PhpLiteral::docblockSafe($componentName).'".']);

        foreach ($this->state->files as $name => $file) {
            $this->state->responseFiles[$name] = $file;
        }
        $this->state->files = $mainFiles;

        return $this->componentResponseClasses[$componentName] = $className;
    }

    /**
     * Emit a per-operation response Data class (issue #129) for an INLINE
     * (non-`$ref`) JSON success-response object schema, the symmetric twin of
     * {@see generateBodyData()} (the inline-request path, issue #76) on the
     * response side and the inline counterpart of
     * {@see generateComponentResponseData()} (the component-response path,
     * issue #116). It reuses the EXACT emission pipeline the component Data
     * classes go through (emitData: properties, rules(), nested inline classes,
     * closed-object enforcement, defaults), so an inline response is typed with
     * the same fidelity as a `$ref` response.
     *
     * Must be called AFTER generate(): the pipeline resolves `$ref` properties
     * against the run's component registry and alias caches. The collected
     * files (the response class plus any nested classes it spawned) are exposed
     * via {@see responseFiles()}, the same bucket the component-response path
     * drains into; the class name is reserved in the run's allocator so it can
     * never collide with a component class, a body class, or a query class (a
     * clash suffixes deterministically, e.g. `..._2`).
     *
     * Like the request twin, only an OBJECT schema synthesizes a class: a
     * non-object inline schema (array, scalar, union, enum, free-form map)
     * returns null with a warning, and the caller keeps the established
     * JsonResponse fallback.
     *
     * A response is server OUTPUT, so a schema that splits fields with
     * readOnly/writeOnly emits the READ shape (writeOnly properties dropped,
     * readOnly kept), the same variant a component `$ref` response is typed
     * against and the opposite of the write variant a request body takes.
     *
     * @param  string  $baseName  StudlyCaps operation context (operationId or the method+path fallback), without suffix
     * @param  string  $operationLabel  "GET /pets", for warning messages
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) can place the class (and its nested classes) in its operation's tag group; ignored in the flat layout
     * @return string|null the reserved response class name, or null when the schema cannot type a response
     */
    public function generateInlineResponseData(string $baseName, string $operationLabel, SchemaNode $schema, ?string $tag = null): ?string
    {
        // Degradation warnings inside the response pipeline name the
        // operation, not a schema: an inline response schema is
        // operation-owned, exactly like generateBodyData().
        $this->state->warningContext = sprintf('Response of operation %s', $operationLabel);

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->state->warnings[sprintf(
                'Operation %s: the inline response schema was not generated as a typed Data class (%s); the return type falls back to JsonResponse.',
                $operationLabel,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->state->names->reserve($this->state->options->withSuffix($baseName.'Response'));

        // A per-operation class belongs unambiguously to its operation's tag
        // group (issue #93); nested classes it spawns follow it through the
        // emission scope. Null in the flat layout.
        $this->state->fileGroups[$className] = $this->groupForTag($tag);

        // Same bucket discipline as generateComponentResponseData(): emit into
        // a clean bucket and collect into $responseFiles for the planner.
        $mainFiles = $this->state->files;
        $this->state->files = [];

        $variant = ($this->hasReadWriteFlags)($schema) ? 'read' : 'all';
        ($this->emitData)($className, $schema, 0, $variant, ['Response of '.PhpLiteral::docblockSafe($operationLabel).'.']);

        foreach ($this->state->files as $name => $file) {
            $this->state->responseFiles[$name] = $file;
        }
        $this->state->files = $mainFiles;

        return $className;
    }

    /**
     * Emit the SHARED Data class of a component request body (issue #110)
     * whose content is multipart/form-data, mirroring
     * {@see generateComponentBodyData()} exactly but through the multipart
     * pipeline of {@see generateMultipartBodyData()}: binary root parts type
     * UploadedFile with file rules, and a schema-level `$ref` is re-emitted
     * with multipart semantics rather than typed against the JSON-semantics
     * component class. One shared `<Component>RequestData` class, cached on
     * success only.
     *
     * @param  string  $componentName  the raw `components.requestBodies` key
     * @param  string  $operationLabel  "POST /pets", for warning messages
     * @param  ?string  $tag  the single tag shared by every operation referencing this component, or null (mixed tag groups or tagless), which places the shared class at the flat root (issue #93)
     * @return string|null the shared body class name, or null when the schema cannot type a body
     */
    public function generateComponentMultipartBodyData(string $componentName, string $operationLabel, SchemaNode|ReferenceNode $schema, ?string $tag = null): ?string
    {
        $existing = $this->componentBodyClasses[$componentName] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        $this->state->warningContext = sprintf('Multipart request body component "%s"', $componentName);

        if ($schema instanceof ReferenceNode) {
            $name = SchemaPointer::refName($schema->pointer());
            if ($name === null || ! isset($this->state->registry[$name]) || $this->state->registry[$name]['kind'] !== 'data') {
                $this->state->warnings[sprintf(
                    'Operation %s: the multipart/form-data request body component "%s" has a $ref schema ("%s") that does not resolve to an object component schema; the controller method falls back to Illuminate\Http\Request.',
                    $operationLabel,
                    $componentName,
                    $schema->pointer(),
                )] = true;

                return null;
            }

            $schema = $this->state->registry[$name]['schema'];
        }

        $reason = $this->bodySkipReason($schema);
        if ($reason !== null) {
            $this->state->warnings[sprintf(
                'Operation %s: the multipart/form-data request body component "%s" was not generated as a typed Data class (%s); the controller method falls back to Illuminate\Http\Request.',
                $operationLabel,
                $componentName,
                $reason,
            )] = true;

            return null;
        }

        $className = $this->state->names->reserve(
            $this->state->options->withSuffix(PhpIdentifier::toClassName($componentName).'Request'),
        );
        $this->state->fileGroups[$className] = $this->groupForTag($tag);

        $mainFiles = $this->state->files;
        $this->state->files = [];
        $this->state->multipartBody = true;

        $variant = ($this->hasReadWriteFlags)($schema) ? 'write' : 'all';
        ($this->emitData)($className, $schema, 0, $variant, ['Multipart request body component "'.PhpLiteral::docblockSafe($componentName).'".']);

        $this->state->multipartBody = false;
        foreach ($this->state->files as $name => $file) {
            $this->state->bodyFiles[$name] = $file;
        }
        $this->state->files = $mainFiles;

        return $this->componentBodyClasses[$componentName] = $className;
    }

    /**
     * Why an inline request-body schema cannot become a typed Data class, or
     * null when it can. The bar mirrors the component classification exactly:
     * only the shapes that would have become a `kind: data` component (an
     * object with named properties, an allOf merge, or a legitimately empty
     * `type: object`) synthesize a class. A pure map resolves to a typed array
     * (no class to type a param against), and a scalar, array, union, or enum
     * shape is a non-object alias there too. An enum (including the float-enum
     * form) is skipped deliberately: the component pipeline wraps a float enum
     * in a single-`value` Data class, but for a request BODY that wrapper would
     * change the wire format (the payload would have to nest under `value`),
     * false-rejecting every spec-valid request.
     */
    private function bodySkipReason(SchemaNode $schema): ?string
    {
        if (SchemaFacts::isPureMap($schema)) {
            return 'it is an object map with only additionalProperties, which resolves to a typed array, not a Data class';
        }

        if (SchemaFacts::isEnum($schema) || SchemaFacts::isScalarEnumComponent($schema)) {
            return 'it is an enum, not an object shape';
        }

        if ($this->notEmptyArray($schema->oneOf) || $this->notEmptyArray($schema->anyOf)) {
            return 'it is a oneOf/anyOf union, not a single object shape';
        }

        // A bare allOf-of-one-$ref is an alias of its target (the chained-alias
        // shape, see promoteChainedAliases). When the target is a generated
        // Data class the allOf merge below recovers its full property set, so
        // synthesis stays correct. A target that is NOT an object Data class
        // (a scalar/array/union alias, a map, an enum, or an unresolved
        // pointer) would merge to an empty class that silently drops the
        // payload, so it keeps the Request fallback instead.
        $aliasRef = SchemaFacts::bareAllOfRef($schema);
        if ($aliasRef !== null) {
            $name = SchemaPointer::refName($aliasRef->pointer());
            if ($name === null || ! isset($this->state->registry[$name]) || $this->state->registry[$name]['kind'] !== 'data') {
                return 'it is an allOf alias of a non-object component, so no Data class can type it';
            }
        }

        $primary = SchemaFacts::normalizeTypes($schema)[0] ?? null;

        if ($primary === 'object'
            || $this->notEmptyArray($schema->properties)
            || $this->notEmptyArray($schema->allOf)
        ) {
            return null;
        }

        return 'it is not an object schema';
    }

    /**
     * Classify one root property of a multipart/form-data body (issue #75) as
     * an uploaded-file part, or null for a regular field. `kind` is 'file' for
     * a binary string and 'files' for an array of binary items; `schema` is
     * the (alias-resolved) part schema the nullability and array-count bounds
     * are read from; `leaf` is the binary string schema the `mimetypes:`
     * constraint is read from (the items schema for an array part).
     *
     * A `$ref` part (or array items entry) is followed through the non-object
     * alias caches so `file: {$ref: BinaryFile}` is still recognized as an
     * upload. Without that resolution the alias path in buildRules() would
     * emit a `string` rule that false-rejects every actual UploadedFile, which
     * is worse than the old no-validation fallback. A `$ref` to a Data class
     * or enum returns null and keeps its normal typing.
     *
     * @return array{kind: 'file'|'files', schema: SchemaNode, leaf: SchemaNode}|null
     */
    public function multipartFilePart(SchemaNode|ReferenceNode $schema): ?array
    {
        $resolved = $this->multipartPartSchema($schema);
        if ($resolved === null) {
            return null;
        }

        if ($this->isBinaryString($resolved)) {
            return ['kind' => 'file', 'schema' => $resolved, 'leaf' => $resolved];
        }

        if ((SchemaFacts::normalizeTypes($resolved)[0] ?? null) === 'array') {
            $items = $resolved->items;
            if ($items instanceof SchemaNode || $items instanceof ReferenceNode) {
                $leaf = $this->multipartPartSchema($items);
                if ($leaf !== null && $this->isBinaryString($leaf)) {
                    return ['kind' => 'files', 'schema' => $resolved, 'leaf' => $leaf];
                }
            }
        }

        return null;
    }

    /**
     * Resolve a multipart part schema for binary-file detection: an inline
     * Schema as-is, a `$ref` (or chained allOf-of-$ref alias) to a non-object
     * alias component to its terminal schema. Null for anything else.
     */
    private function multipartPartSchema(SchemaNode|ReferenceNode $schema): ?SchemaNode
    {
        if ($schema instanceof SchemaNode) {
            return $schema;
        }

        $alias = $this->state->referencedAliasSchema($schema);

        return $alias === null ? null : $this->state->terminalAliasSchema($alias);
    }

    private function isBinaryString(SchemaNode $schema): bool
    {
        return (SchemaFacts::normalizeTypes($schema)[0] ?? null) === 'string' && $schema->format === 'binary';
    }

    /**
     * The resolved PHP type of a multipart file part: `UploadedFile` for a
     * single file, a typed `array<int, UploadedFile>` for an array of files.
     *
     * @param  array{kind: 'file'|'files', schema: SchemaNode, leaf: SchemaNode}  $part
     */
    public function multipartFileType(array $part): ResolvedType
    {
        $nullable = SchemaFacts::isNullable($part['schema']);

        if ($part['kind'] === 'file') {
            return new ResolvedType('UploadedFile', $nullable, null, [self::UPLOADED_FILE_FQCN]);
        }

        return new ResolvedType('array', $nullable, 'array<int, UploadedFile>', [self::UPLOADED_FILE_FQCN]);
    }

    /**
     * The validation rules of a multipart file part: presence as usual, then
     * `file` (plus the `mimetypes:` constraint) on the value itself for a
     * single file, or `array` plus the spec's minItems/maxItems bounds with
     * the file rules on the `.*` wildcard for an array of files.
     *
     * @param  array{kind: 'file'|'files', schema: SchemaNode, leaf: SchemaNode}  $part
     * @return array{0: list<string>, 1: array<string, list<string>>, 2: bool} property rules,
     *                                                                         wildcard item rules keyed by suffix, whether Rule:: is used
     */
    public function multipartFileRules(array $part, bool $required, ResolvedType $type): array
    {
        $rules = $this->rules->presenceRules($required, $type->nullable);

        if ($part['kind'] === 'file') {
            return [array_merge($rules, $this->fileLeafRules($part['leaf'])), [], false];
        }

        return [
            array_merge($rules, ["'array'"], $this->rules->arrayCountRules($part['schema'])),
            ['.*' => $this->fileLeafRules($part['leaf'])],
            false,
        ];
    }

    /**
     * The per-file rules of one uploaded-file value: Laravel's `file` rule,
     * plus a `mimetypes:` constraint when the schema pins the part's media
     * type via `contentMediaType` (a JSON Schema 2020-12 keyword OpenAPI 3.1
     * admits). Deliberately NO size rule: OpenAPI has no standard keyword for
     * a file's byte size (`maxLength` bounds a STRING's character length, and
     * Laravel's file `max:` counts kilobytes), so no clean mapping exists and
     * none is invented.
     *
     * @return list<string>
     */
    private function fileLeafRules(SchemaNode $schema): array
    {
        $rules = ["'file'"];

        $mediaType = $this->contentMediaTypeOf($schema);
        if ($mediaType !== null) {
            $rules[] = "'mimetypes:".$mediaType."'";
        }

        return $rules;
    }

    /**
     * The schema's `contentMediaType`, when it is a well-formed media type.
     * A first-class typed keyword on SchemaNode (issue #104). The spec is
     * untrusted input and the value lands inside a single-quoted rule string,
     * so anything not matching the strict type/subtype token shape (including
     * the `image/*` wildcard form Laravel's mimetypes rule understands) is
     * dropped rather than escaped.
     */
    private function contentMediaTypeOf(SchemaNode $schema): ?string
    {
        $value = $schema->contentMediaType;

        if ($value === null) {
            return null;
        }

        return preg_match('~^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*/(\*|[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*)$~', $value) === 1
            ? $value
            : null;
    }

    /**
     * Why a query parameter cannot become a typed, validated property, or null
     * when it can. The bar: the value must arrive through Laravel's flat query
     * parsing (`key=value` scalars, `key[]=value` arrays) in a shape the
     * generated rules describe. Anything else is skipped with a warning;
     * generating wrong rules (false-rejecting valid requests) would be worse
     * than generating none.
     */
    private function querySkipReason(ParameterNode $parameter): ?string
    {
        if ($parameter->name === '') {
            return 'it has no usable name';
        }

        $schema = $parameter->schema;

        $style = $parameter->style;
        if ($style === 'deepObject') {
            // A deepObject parameter serializes an OBJECT as bracketed keys
            // (?filter[gte]=10&filter[lte]=20), which PHP/Laravel parse NATIVELY
            // into a nested array (['filter' => ['gte' => '10', 'lte' => '20']]).
            // No manual splitting is needed (unlike the delimited-array case of
            // issue #132): the nested structure is already in $request->query().
            // So an OBJECT deepObject param is synthesized as a nested object
            // property whose own Data class carries the per-property rules, the
            // same machinery a nested object body property uses (issue #131).
            // The form is only meaningful with explode: true (the deepObject
            // default), so explode: false is still skipped, and a non-object
            // schema (a scalar/array, which deepObject cannot serialize as a
            // flat key) keeps the skip too.
            if ($parameter->explode === false) {
                return 'style "deepObject" requires explode: true to serialize the object keys';
            }

            if ($schema === null) {
                return 'it declares no schema (content-typed query parameters are not supported yet)';
            }

            if (! $this->isQueryObjectSchema($schema)) {
                return 'style "deepObject" only serializes an object schema, but this parameter is not an object';
            }

            return null;
        }

        if ($schema === null) {
            return 'it declares no schema (content-typed query parameters are not supported yet)';
        }

        // A delimited (non-exploded) array arrives as ONE joined string
        // (?ids=1,2,3 for form, space- or pipe-joined for the delimited styles)
        // rather than the per-item ?ids[]=1&ids[]=2 shape the `array` rule
        // expects. The fromQuery() factory splits the string on the declared
        // delimiter before validating (issue #132), so these now participate in
        // validation; queryArrayDelimiter() returns the delimiter when this
        // applies. A spaceDelimited/pipeDelimited style on a NON-array schema is
        // meaningless (those styles only serialize arrays), so it falls through
        // to the shape check and is treated like a plain scalar.

        return $this->queryShapeSkipReason($schema, 0);
    }

    /**
     * The delimiter a delimited (non-exploded) array query parameter serializes
     * its items with (issue #132), or null when the parameter is NOT a delimited
     * array, in which case the existing handling applies unchanged:
     *
     *   - form + explode: true (the query default)  ->  null (repeated ?ids[]=1)
     *   - form + explode: false                      ->  "," (?ids=1,2,3)
     *   - spaceDelimited (array, any explode)        ->  " " (?ids=1 2 3)
     *   - pipeDelimited (array, any explode)          ->  "|" (?ids=1|2|3)
     *
     * Only an array schema is delimited; the styles are no-ops on a scalar, so
     * a non-array schema always returns null. The default style is `form` and
     * the default `explode` for `form` is true, so an UNSPECIFIED explode keeps
     * the repeated-key path (null); only an explicit `explode: false` (or a
     * delimited style, whose meaningful form is explode: false) triggers the
     * split path.
     */
    private function queryArrayDelimiter(ParameterNode $parameter): ?string
    {
        $schema = $parameter->schema;
        if ($schema === null || ! $this->isQueryArraySchema($schema)) {
            return null;
        }

        $style = $parameter->style;
        if ($style === 'spaceDelimited') {
            return ' ';
        }
        if ($style === 'pipeDelimited') {
            return '|';
        }

        // form style (explicit or default): only explode: false is delimited.
        if ($parameter->explode === false) {
            return ',';
        }

        return null;
    }

    /**
     * Whether a query parameter's schema is an array (directly, or through a
     * non-object alias component that resolves to an array). Used only for the
     * delimiter detection above.
     */
    private function isQueryArraySchema(SchemaNode|ReferenceNode $schema): bool
    {
        if ($schema instanceof ReferenceNode) {
            $name = SchemaPointer::refName($schema->pointer());

            return $name !== null
                && isset($this->state->aliasSchemas[$name])
                && $this->types->resolveAlias($name)->declaration === 'array';
        }

        return (SchemaFacts::normalizeTypes($schema)[0] ?? null) === 'array';
    }

    /**
     * Whether a deepObject query parameter's schema is an object that can be
     * synthesized into a nested Data-class property (issue #131): an inline
     * object (an explicit `type: object`, an `allOf` merge, or a bare
     * `properties` set), or a `$ref` to a generated object Data component. A
     * pure map, a scalar, an array, a union, or an enum is NOT an object the
     * nested-object pipeline can type, so it keeps the deepObject skip.
     */
    private function isQueryObjectSchema(SchemaNode|ReferenceNode $schema): bool
    {
        if ($schema instanceof ReferenceNode) {
            $name = SchemaPointer::refName($schema->pointer());

            return $name !== null
                && isset($this->state->registry[$name])
                && $this->state->registry[$name]['kind'] === 'data';
        }

        if (SchemaFacts::isPureMap($schema)) {
            return false;
        }

        $primary = SchemaFacts::normalizeTypes($schema)[0] ?? null;

        return $primary === 'object'
            || $this->notEmptyArray($schema->properties)
            || $this->notEmptyArray($schema->allOf);
    }

    /**
     * The shape-level skip reason for a query parameter schema, or null when
     * the shape survives flat form serialization: scalars, enums (inline or
     * `$ref` to a generated enum), scalar unions, and arrays of those. Objects,
     * object maps, and arrays of objects have no flat `key=value` form, so they
     * are skipped. Recurses through array items so an array-of-array-of-object
     * is caught at any depth (bounded; deeper nesting is rejected outright,
     * since bracket query strings beyond a few levels are not a real wire
     * format).
     */
    private function queryShapeSkipReason(SchemaNode|ReferenceNode $schema, int $depth): ?string
    {
        if ($depth > self::QUERY_MAX_ARRAY_DEPTH) {
            return 'it nests arrays too deeply for query-string serialization';
        }

        if ($schema instanceof ReferenceNode) {
            $name = SchemaPointer::refName($schema->pointer());
            if ($name === null) {
                // External or non-schema pointer: degrades to mixed,
                // presence-only, exactly like a body property would.
                return null;
            }

            if (isset($this->state->registry[$name]) && $this->state->registry[$name]['kind'] === 'data') {
                return 'it is an object, which has no flat query-string serialization';
            }

            if (isset($this->state->mapSchemas[$name])) {
                return 'it is an object map, which has no flat query-string serialization';
            }

            if (isset($this->state->aliasSchemas[$name])) {
                $resolved = $this->types->resolveAlias($name);
                if ($resolved->isMap) {
                    return 'it is an object map, which has no flat query-string serialization';
                }
                if ($resolved->dataCollectionOf !== null) {
                    return 'it is an array of objects, which has no flat query-string serialization';
                }
            }

            // A generated enum, a scalar/array alias, or an unknown component
            // (degrades to mixed): all safe.
            return null;
        }

        if (SchemaFacts::isPureMap($schema)) {
            return 'it is an object map, which has no flat query-string serialization';
        }

        $primary = SchemaFacts::normalizeTypes($schema)[0] ?? null;

        if ($primary === 'object' || $this->notEmptyArray($schema->properties) || $this->notEmptyArray($schema->allOf)) {
            return 'it is an object, which has no flat query-string serialization';
        }

        if ($primary === 'array') {
            $items = $schema->items;
            if ($items instanceof SchemaNode || $items instanceof ReferenceNode) {
                return $this->queryShapeSkipReason($items, $depth + 1);
            }
        }

        // Scalars, inline enums/consts, oneOf/anyOf unions (which resolve to a
        // native scalar union or degrade to presence-only mixed), and untyped
        // schemas are all safe for flat form serialization.
        return null;
    }

    /**
     * The tag group a per-operation class (query, issue #63; body, issue #76)
     * belongs to: its operation's tag group, which is unambiguous because an
     * operation has exactly one controller tag. Null for a tagless caller or
     * for the reserved 'Support' group.
     */
    private function groupForTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return TagGroups::forTag($tag);
    }

    private function notEmptyArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
