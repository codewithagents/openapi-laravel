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
        /*
         * When true, a schema that declares `additionalProperties: false` emits a
         * rule rejecting any key outside its declared property set (a closed
         * object shape, issue #30). Off by default: enforcing a closed shape has
         * a forward-compatibility hazard (a producer adding a field would break a
         * consumer that has not regenerated), so strict rejection is opt in.
         */
        public bool $enforceClosedObjects = false,
    ) {}
}
