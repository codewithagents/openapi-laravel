<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The settings a standalone-binary run can read from an openapi-laravel.json
 * config file. The keys mirror config/openapi-laravel.php one to one (spec,
 * output.path/namespace/suffix/prune, controllers.enabled/path/namespace,
 * routes.enabled/path, max_depth, max_bytes), so a team can keep one mental
 * model across the artisan command and the framework-free binary.
 *
 * Every property is nullable: null means "not set in the file", letting the
 * binary apply its flag-over-config-over-default precedence per value.
 */
final readonly class StandaloneConfig
{
    public function __construct(
        public ?string $spec = null,
        public ?string $outputPath = null,
        public ?string $namespace = null,
        public ?string $suffix = null,
        public ?bool $prune = null,
        public ?bool $controllersEnabled = null,
        public ?string $controllerPath = null,
        public ?string $controllerNamespace = null,
        public ?bool $routesEnabled = null,
        public ?string $routesPath = null,
        public ?int $maxDepth = null,
        public ?int $maxBytes = null,
    ) {}
}
