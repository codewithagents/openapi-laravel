<?php

declare(strict_types=1);

return [

    /*
     * Path to the OpenAPI document (YAML or JSON) used as the source of truth.
     */
    'spec' => env('OPENAPI_LARAVEL_SPEC', base_path('openapi.yaml')),

    /*
     * Where generated classes are written, and the namespace they live under.
     *
     * Data classes and enums are emitted in the tag-grouped layout (issue
     * #93): a class solely owned by one tag group lands in a per-tag
     * subdirectory with the namespace following the directory
     * (data/Pet/PetData.php under App\Data\Pet), mirroring the per-tag
     * controller grouping. Multi-tag, unreferenced, and reserved-'Support'
     * schemas stay at the flat root; per-operation query and request-body
     * classes follow their operation's tag; the inlined runtime Support
     * classes always stay in Support/.
     */
    'output' => [
        'path' => app_path('Data'),
        'namespace' => 'App\\Data',

        /*
         * Suffix appended to generated Data class names (e.g. Customer ->
         * CustomerData). Enums are never suffixed. Set to '' to disable.
         */
        'suffix' => 'Data',

        /*
         * Delete existing *.php files in the output directory before writing,
         * so a removed schema does not leave a stale class behind.
         */
        'prune' => false,

        /*
         * Validation extension trait (issue #83). Generated Data classes are
         * overwritten on every regenerate, so never edit them. To customize
         * validation messages or attribute names, set this to the
         * fully-qualified name of a trait YOU own (it is never generated or
         * touched), e.g. 'App\\Support\\ApiValidationMessages'. Every
         * generated Data class then carries `use ApiValidationMessages;`, and
         * laravel-data picks up the trait's static messages() / attributes()
         * methods when validating. Null (the default) emits no trait line.
         */
        'validation_trait' => null,
    ],

    /*
     * Server scaffold: generate one abstract controller per tag with an
     * abstract method per operation, typed against the generated Data classes.
     * Enabled by default; opt out here or pass --no-controllers to the command
     * (--controllers force-enables it again). Only the Abstract* files are ever
     * written, so your concrete controllers are never touched or pruned.
     *
     * Method names follow the Laravel conventions (issue #94): a clean
     * RESTful operation gets the conventional controller method name (and the
     * matching route name) instead of the operationId-derived one:
     *
     *   GET        collection path (/pets)         -> index
     *   POST       collection path (/pets)         -> store
     *   GET        item path (/pets/{petId})       -> show
     *   PUT/PATCH  item path (/pets/{petId})       -> update
     *   DELETE     item path (/pets/{petId})       -> destroy
     *
     * An item path is one whose last segment is a path parameter; every other
     * path is a collection path. Anything ambiguous falls back to the
     * operationId-derived name deterministically: when two operations in the
     * SAME controller map to the same conventional name (say, two collection
     * GETs under one tag), BOTH keep their operationId-derived name. Non-CRUD
     * operations (POST on an item path, HEAD, OPTIONS, ...) always keep
     * theirs, and the Data layer (including query classes) stays
     * operationId-derived.
     */
    'controllers' => [
        'enabled' => true,
        'path' => app_path('Http/Controllers/Api'),
        'namespace' => 'App\\Http\\Controllers\\Api',

        /*
         * Base class every generated abstract controller extends (issue #83).
         * Null (the default) keeps the abstracts framework-light, with no base
         * class at all. Set a fully-qualified class name, e.g.
         * 'App\\Http\\Controllers\\Controller', to root the generated scaffold
         * in your project's own controller hierarchy.
         */
        'base_class' => null,
    ],

    /*
     * Server scaffold: a single generated routes file with one Route:: entry
     * per spec operation, pointing at the concrete controller classes. Enabled
     * by default; opt out here or pass --no-routes to the command (--routes
     * force-enables it again).
     */
    'routes' => [
        'enabled' => true,
        'path' => base_path('routes/api.generated.php'),

        /*
         * Wrap the generated routes in one Route::group block. `middleware`
         * is a list of middleware names (each entry its own string, never
         * comma-split, so parameterized names like 'throttle:60,1' work);
         * `prefix` is a URI prefix for every generated route. Leave both
         * empty (the default) for the flat, ungrouped routes file. Every
         * route also carries a ->name() derived from its operationId, so the
         * route() helper and route-based authorization can target it.
         */
        'middleware' => [],
        'prefix' => '',
    ],

    /*
     * Map the spec's security schemes to Laravel middleware (issue #77).
     * Keys are scheme names from components.securitySchemes; values are one
     * middleware name or a list of names (each entry its own string, never
     * comma-split). Every generated route whose operation requires a mapped
     * scheme gets ->middleware([...]) with the mapped names.
     *
     * Semantics: operation-level `security` overrides the global one, and an
     * explicit `security: []` makes that operation public (no middleware).
     * Multiple schemes in one requirement object (AND) apply all mapped
     * middleware; multiple requirement objects (OR) cannot be expressed as
     * middleware, so the first requirement object is enforced and a warning
     * names the ignored alternatives. A scheme the spec requires but the map
     * does not name leaves its routes open and warns at generation time; map
     * it to an empty list to acknowledge it is handled elsewhere and silence
     * the warning. Empty (the default) maps nothing.
     *
     * Example: ['bearerAuth' => 'auth:sanctum', 'apiKey' => ['auth.apikey', 'throttle:60,1']]
     */
    'security' => [
        'middleware_map' => [],
    ],

    /*
     * Enforce closed object shapes: when true, a schema declaring
     * `additionalProperties: false` emits a rule that rejects any input key
     * outside its declared property set (issue #30). Enabled by default, because
     * the spec is the source of truth: a schema that explicitly closed its shape
     * gets that shape enforced. Set this to false (or pass
     * --no-enforce-closed-objects to the command) to accept unknown keys, which
     * is the lenient, forward-compatible behavior some consumers want during
     * contract evolution when a producer may add fields ahead of a regenerate.
     */
    'enforce_closed_objects' => true,

    /*
     * Maximum schema nesting depth the parser will follow before bailing out.
     * Guards against pathological or maliciously deep specs.
     */
    'max_depth' => 64,

    /*
     * Maximum raw spec file size, in bytes, accepted before parsing. The spec is
     * untrusted input fed to a YAML parser that expands anchors/aliases before we
     * see it (an alias-bomb vector the parser cannot disable), so a pre-parse size
     * guard caps the blast radius. The default (24 MiB) sits well above the
     * largest real-world specs. Raise it only for trusted inputs, and prefer
     * OS-level resource limits when running against untrusted specs.
     */
    'max_bytes' => 25_165_824,

    /*
     * Subset generation (issue #44). Restrict the run to a slice of the spec
     * instead of every component and operation. Each list is automatically
     * closed over its transitive `$ref` dependencies, so the output stays
     * self-consistent (no dangling reference, no missing union variant).
     *
     * - only_tags: keep operations carrying any of these tags (their
     *   controllers and routes), plus every schema they reference.
     * - only_schemas: keep these component schemas, plus everything reachable
     *   from them.
     *
     * Both may be combined (the union of the two selections plus the closure).
     * Each accepts a comma-separated string or an array of names; the
     * --only-tags / --only-schemas flags override these keys. Empty (the
     * default) generates the full spec, byte-identical to before. A name that
     * matches nothing in the spec is a hard error, not a silent empty slice.
     */
    'only_tags' => env('OPENAPI_LARAVEL_ONLY_TAGS', ''),
    'only_schemas' => env('OPENAPI_LARAVEL_ONLY_SCHEMAS', ''),

    /*
     * Path-prefix exclusion (issue #96). Every operation whose path starts
     * with one of these literal prefixes is dropped before controllers,
     * routes, and the subset closure are computed, so it produces no
     * controller method and no route. Useful for spec artifacts such as a
     * duplicated swagger-mirror route group ('/api/v1/swagger/...').
     *
     * A list of strings, each entry its own prefix (never comma-split, a
     * literal URL path may contain a comma). Matching is a plain
     * case-sensitive prefix test on the path as written in the spec. The
     * repeatable --exclude-path-prefix flag overrides this key. Empty (the
     * default) excludes nothing. A prefix that matches no path is a warning,
     * not an error.
     */
    'exclude_path_prefixes' => [],

];
