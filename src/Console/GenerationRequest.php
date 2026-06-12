<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The normalized inputs the planner needs to compute what the generator would
 * write: the spec, the model output target, and the optional server targets.
 *
 * Both entry points (the artisan command and the standalone binary) build one
 * of these from their own option sources, then hand it to GenerationPlanner.
 * Computing the plan is identical for generate and check, so this is the single
 * place the two share their inputs.
 *
 * Paths may be null (not configured) or empty; the planner is responsible for
 * rejecting an empty spec/output and for rejecting a requested server target
 * that has no path, mirroring the original command error handling.
 *
 * @internal
 */
final readonly class GenerationRequest
{
    /**
     * @param  list<string>  $onlyTags  subset tag selection (issue #44); empty means the full spec
     * @param  list<string>  $onlySchemas  subset schema selection (issue #44); empty means the full spec
     * @param  list<string>  $routesMiddleware  middleware names the generated routes are grouped under (issue #71); empty means no group
     * @param  list<string>  $excludePathPrefixes  path-prefix exclusion (issue #96); empty means no path is excluded
     * @param  array<string, list<string>>  $securityMiddlewareMap  security scheme name => middleware names (issue #77); empty means no mapping
     */
    public function __construct(
        public ?string $spec,
        public ?string $output,
        public string $namespace,
        public string $suffix,
        public int $maxDepth,
        public ?int $maxBytes,
        public bool $controllers,
        public ?string $controllerPath,
        public string $controllerNamespace,
        public bool $routes,
        public ?string $routesPath,
        /*
         * Closed-object enforcement: when true, a schema declaring
         * additionalProperties: false emits a rule rejecting unknown keys
         * (issue #30). Default true honors the spec; opt out with
         * --no-enforce-closed-objects (or enforce_closed_objects: false) for
         * lenient, forward-compatible output during contract evolution.
         */
        public bool $enforceClosedObjects = true,
        /*
         * Subset generation (issue #44). When non-empty, the run is restricted to
         * the named tags' operations and/or the named component schemas, each
         * automatically closed over its transitive `$ref` dependencies so the
         * output stays self-consistent. Empty lists (the default) generate the
         * full spec, byte-identical to a run with no subset flags.
         *
         * @var list<string>
         */
        public array $onlyTags = [],
        /*
         * @var list<string>
         */
        public array $onlySchemas = [],
        /*
         * Route group settings (issue #71). When middleware names and/or a URI
         * prefix are configured (routes.middleware / routes.prefix), the
         * generated routes are wrapped in one Route::middleware(...)->
         * prefix(...)->group(...) block. The defaults (no middleware, no
         * prefix) keep the flat routes file unchanged. Config-only: there are
         * no CLI flags for these.
         */
        public array $routesMiddleware = [],
        public ?string $routesPrefix = null,
        /*
         * Path-prefix exclusion (issue #96). Every operation whose literal spec
         * path starts with one of these prefixes is dropped BEFORE the subset
         * closure and the operation collector run, so it produces no controller
         * method, no route, and no query class. Entries are never comma-split
         * (a literal URL path may contain a comma); the flag is repeatable
         * instead. Empty (the default) excludes nothing, byte-identical to a
         * run without the flag.
         *
         * @var list<string>
         */
        public array $excludePathPrefixes = [],
        /*
         * Security scheme to middleware mapping (issue #77). Keys are scheme
         * names from the spec's components.securitySchemes; values are the
         * middleware names a route requiring that scheme must carry. An empty
         * list value acknowledges a scheme as handled elsewhere (no middleware,
         * no warning). Config-only, like the route group settings: there is no
         * CLI flag, a map is config-shaped.
         */
        public array $securityMiddlewareMap = [],
        /*
         * Laravel-convention method names (issue #94). When true, a clean
         * RESTful operation gets the conventional controller method name
         * (index/show/store/update/destroy) and the matching route name;
         * ambiguous or non-CRUD operations keep their operationId-derived
         * name. Default false (opt-in), so the default output stays
         * byte-identical to a run without the flag. Resolved like the other
         * toggles: --laravel-conventions / --no-laravel-conventions beat the
         * controllers.laravel_conventions config key, which beats this
         * built-in default (disabled).
         */
        public bool $laravelConventions = false,
        /*
         * Concrete controller stub scaffolding (issue #78). When true, the
         * plan additionally contains one CATEGORY_STUB file per concrete
         * controller the routes file references, each extending its generated
         * abstract controller. Only the scaffold surfaces set this; generate
         * and check never do, so the drift gate never sees a stub.
         */
        public bool $stubs = false,
        /*
         * Controller base class (issue #83). When non-null, every generated
         * abstract controller extends this fully-qualified class instead of
         * standing alone. Null (the default) keeps the historical
         * base-class-free output. Validated as a legal FQCN by the planner
         * before any file is planned.
         */
        public ?string $controllerBaseClass = null,
        /*
         * Validation extension trait (issue #83). When non-null, every
         * generated Data class carries `use <Trait>;` so the user-owned
         * trait's static messages() / attributes() methods customize
         * validation errors without editing generated (and regenerated)
         * files. Null (the default) emits no trait line. Validated as a
         * legal FQCN by the planner before any file is planned.
         */
        public ?string $validationTrait = null,
    ) {}
}
