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
 */
final readonly class GenerationRequest
{
    /**
     * @param  list<string>  $onlyTags  subset tag selection (issue #44); empty means the full spec
     * @param  list<string>  $onlySchemas  subset schema selection (issue #44); empty means the full spec
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
         * Opt-in closed-object enforcement: when true, a schema declaring
         * additionalProperties: false emits a rule rejecting unknown keys
         * (issue #30). Default false keeps the lenient, forward-compatible output.
         */
        public bool $enforceClosedObjects = false,
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
    ) {}
}
