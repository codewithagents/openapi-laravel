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
    ],

    /*
     * Maximum schema nesting depth the parser will follow before bailing out.
     * Guards against pathological or maliciously deep specs.
     */
    'max_depth' => 64,

];
