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
    ],

    /*
     * Maximum schema nesting depth the parser will follow before bailing out.
     * Guards against pathological or maliciously deep specs.
     */
    'max_depth' => 64,

];
