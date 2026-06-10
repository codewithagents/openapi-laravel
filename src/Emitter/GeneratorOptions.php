<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Generation settings, mirrored from the published openapi-laravel config.
 * Kept as a plain value object so the generator is testable without booting
 * a Laravel container.
 */
final class GeneratorOptions
{
    public function __construct(
        public readonly string $namespace = 'App\\Data',
        public readonly string $dataSuffix = 'Data',
        public readonly int $maxDepth = 64,
    ) {}
}
