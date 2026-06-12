<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The settings a standalone-binary run can read from an openapi-laravel.json
 * config file. The keys mirror config/openapi-laravel.php one to one (spec,
 * output.path/namespace/suffix/prune/validation_trait,
 * controllers.enabled/path/namespace/base_class,
 * routes.enabled/path/middleware/prefix, security.middleware_map,
 * enforce_closed_objects, max_depth, max_bytes, only_tags, only_schemas,
 * exclude_path_prefixes), so a team can keep one mental model across the
 * artisan command and the framework-free binary.
 *
 * Every property is nullable: null means "not set in the file", letting the
 * binary apply its flag-over-config-over-default precedence per value.
 *
 * @internal
 */
final readonly class StandaloneConfig
{
    /**
     * @param  list<string>|null  $routesMiddleware  middleware names for the route group (issue #71), or null when unset
     * @param  list<string>|null  $onlyTags  subset tag selection (issue #44), or null when unset
     * @param  list<string>|null  $onlySchemas  subset schema selection (issue #44), or null when unset
     * @param  list<string>|null  $excludePathPrefixes  path-prefix exclusion (issue #96), or null when unset
     * @param  array<string, list<string>>|null  $securityMiddlewareMap  security scheme name => middleware names (issue #77), or null when unset
     */
    public function __construct(
        public ?string $spec = null,
        public ?string $outputPath = null,
        public ?string $namespace = null,
        public ?string $suffix = null,
        public ?bool $prune = null,
        /*
         * Validation extension trait (issue #83): the FQCN of a user-owned
         * trait every generated Data class pulls in via `use <Trait>;`, the
         * sanctioned home for static messages() / attributes() methods. Null
         * means the key was not set (no trait line). The loader rejects an
         * empty string, and the planner validates the value as a legal FQCN.
         */
        public ?string $validationTrait = null,
        public ?bool $controllersEnabled = null,
        public ?string $controllerPath = null,
        public ?string $controllerNamespace = null,
        /*
         * Controller base class (issue #83): the FQCN every generated abstract
         * controller extends. Null means the key was not set (the abstracts
         * stay base-class-free). The loader rejects an empty string, and the
         * planner validates the value as a legal FQCN.
         */
        public ?string $controllerBaseClass = null,
        public ?bool $routesEnabled = null,
        public ?string $routesPath = null,
        /*
         * Route group settings (issue #71). routes.middleware is a JSON list
         * of middleware names (never comma-split: a middleware parameter list
         * legitimately contains commas, e.g. throttle:60,1); routes.prefix is
         * a URI prefix string. When either is set the generated routes are
         * wrapped in one Route::group block. Null means the key was not set.
         */
        public ?array $routesMiddleware = null,
        public ?string $routesPrefix = null,
        public ?bool $enforceClosedObjectsEnabled = null,
        public ?int $maxDepth = null,
        public ?int $maxBytes = null,
        /*
         * Subset generation (issue #44). A comma-separated string or a JSON list
         * of tag / component-schema names. Null means the key was not set, in
         * which case the binary generates the full spec.
         *
         * @var list<string>|null
         */
        public ?array $onlyTags = null,
        /*
         * @var list<string>|null
         */
        public ?array $onlySchemas = null,
        /*
         * Path-prefix exclusion (issue #96). A JSON list of literal path
         * prefixes; every operation whose path starts with one of them is
         * dropped before tag/controller/route collection. Never comma-split
         * (a literal URL path may contain a comma). Null means the key was
         * not set, in which case nothing is excluded.
         *
         * @var list<string>|null
         */
        public ?array $excludePathPrefixes = null,
        /*
         * Security scheme to middleware mapping (issue #77). A JSON object
         * whose keys are scheme names from components.securitySchemes and
         * whose values are a middleware name or a list of names (never
         * comma-split). An empty list value acknowledges a scheme as handled
         * elsewhere. Null means the key was not set, in which case no
         * middleware is mapped.
         *
         * @var array<string, list<string>>|null
         */
        public ?array $securityMiddlewareMap = null,
    ) {}
}
