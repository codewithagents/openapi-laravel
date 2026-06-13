<?php

declare(strict_types=1);

return [

    /*
     * Path to the OpenAPI document (YAML or JSON) used as the source of truth.
     * The shared contract lives one level up from this backend app, under
     * e2e/spec/petstore.yaml. base_path() is the backend root (e2e/backend),
     * so the spec sits at ../spec/petstore.yaml relative to it.
     */
    'spec' => env('OPENAPI_LARAVEL_SPEC', base_path('../spec/petstore.yaml')),

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
     * Disabled by default; opt in here or pass --controllers to the command.
     * Only the Abstract* files are ever written, so your concrete controllers
     * are never touched or pruned.
     */
    'controllers' => [
        'enabled' => false,
        'path' => app_path('Http/Controllers/Api'),
        'namespace' => 'App\\Http\\Controllers\\Api',
    ],

    /*
     * Server scaffold: a single generated routes file with one Route:: entry
     * per spec operation, pointing at the concrete controller classes. Disabled
     * by default; opt in here or pass --routes to the command.
     */
    'routes' => [
        'enabled' => false,
        'path' => base_path('routes/api.generated.php'),

        /*
         * #71: wrap every generated route in one Route::group with this prefix
         * and middleware stack. The prefix is applied ON TOP of the /api mount
         * in bootstrap/app.php, so routes become /api/v1/.... The marker
         * middleware (RouteGroupMarker) runs on every generated route and proves
         * the group middleware fires by stamping an X-Route-Group header.
         */
        'prefix' => 'v1',
        'middleware' => ['route-group-marker'],
    ],

    /*
     * Map each spec security scheme name to the route middleware that enforces
     * it. The generator stamps the mapped middleware onto every route whose
     * operation requires that scheme (#77). Here the upload operation requires
     * pet_upload_key (an apiKey header scheme), so the generated uploadFile
     * route carries the api-key middleware below. The other schemes
     * (petstore_auth oauth2, api_key) are intentionally left unmapped: they
     * stay public in this demo and the generator warns about them, which is the
     * documented behaviour we want to exercise.
     */
    'security' => [
        'middleware_map' => [
            'pet_upload_key' => ['api-key'],
        ],
    ],

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

];
