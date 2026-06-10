<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Generation settings, mirrored from the published openapi-laravel config.
 * Kept as a plain value object so the generator is testable without booting
 * a Laravel container.
 */
final readonly class GeneratorOptions
{
    public function __construct(
        public string $namespace = 'App\\Data',
        public string $dataSuffix = 'Data',
        public int $maxDepth = 64,
    ) {}
}
