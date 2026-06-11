<?php

declare(strict_types=1);

return [

    /*
     * Path to the OpenAPI document (YAML or JSON) used as the source of truth.
     */
    'spec' => env('OPENAPI_LARAVEL_SPEC', base_path('openapi.yaml')),

    /*
     * Where generated classes are written, and the namespace they live under.
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
    ],

    /*
     * Server scaffold: generate one abstract controller per tag with an
     * abstract method per operation, typed against the generated Data classes.
     * Enabled by default; opt out here or pass --no-controllers to the command
     * (--controllers force-enables it again). Only the Abstract* files are ever
     * written, so your concrete controllers are never touched or pruned.
     */
    'controllers' => [
        'enabled' => true,
        'path' => app_path('Http/Controllers/Api'),
        'namespace' => 'App\\Http\\Controllers\\Api',
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

];
