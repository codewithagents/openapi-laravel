<?php

declare(strict_types=1);

/*
 * DEMO ONLY: fully permissive CORS so a later SPA milestone can call this API
 * from any origin without friction. Do NOT ship this as-is. A real deployment
 * would pin allowed_origins to the known frontend host(s) and tighten methods
 * and headers to what the client actually uses.
 */

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
