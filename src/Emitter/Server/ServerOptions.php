<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

/**
 * Settings for the server scaffold (abstract controllers + routes file).
 * Kept as a plain value object so the generators are testable without booting
 * a Laravel container. `dataNamespace` must match the namespace the model
 * generator wrote its Data classes under, so imports in controllers resolve.
 */
final readonly class ServerOptions
{
    /**
     * @param  list<string>  $routeMiddleware  middleware names the generated routes are grouped under (issue #71); empty means no group
     * @param  ?string  $routePrefix  URI prefix the generated routes are grouped under (issue #71); null or '' means no prefix
     */
    public function __construct(
        public string $controllerNamespace = 'App\\Http\\Controllers\\Api',
        public string $dataNamespace = 'App\\Data',
        public string $abstractPrefix = 'Abstract',
        public string $controllerSuffix = 'Controller',
        public array $routeMiddleware = [],
        public ?string $routePrefix = null,
    ) {}
}
