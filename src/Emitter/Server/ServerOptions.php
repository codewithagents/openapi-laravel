<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

/**
 * Settings for the server scaffold (abstract controllers + routes file).
 * Kept as a plain value object so the generators are testable without booting
 * a Laravel container. `dataNamespace` must match the namespace the model
 * generator wrote its Data classes under, so imports in controllers resolve.
 */
final class ServerOptions
{
    public function __construct(
        public readonly string $controllerNamespace = 'App\\Http\\Controllers\\Api',
        public readonly string $dataNamespace = 'App\\Data',
        public readonly string $abstractPrefix = 'Abstract',
        public readonly string $controllerSuffix = 'Controller',
    ) {}
}
