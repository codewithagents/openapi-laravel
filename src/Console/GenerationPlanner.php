<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\ResolvedClosure;
use CodeWithAgents\OpenApiLaravel\Emitter\SchemaClosure;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\SubsetSelection;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * The shared generation core. Parses the spec and runs the model + server
 * generators, then computes the full set of files the run would write as
 * absolute path + exact content, WITHOUT touching disk.
 *
 * This is the single place the generate and check paths share. `openapi:generate`
 * writes the returned plan; `openapi:check` compares it against disk. Because the
 * generators are deterministic, the planned content is byte-identical to what a
 * real generate would write, so check is an exact-match comparison.
 *
 * Path computation mirrors what the generate path writes: Data classes and
 * abstract controllers land at "<dir>/<ClassName>.php", and the routes file
 * lands at its configured full path.
 *
 * @throws PlanException on a configuration error (missing spec/output, or a
 *                       requested server target without a path)
 * @throws OptionException when an operator-supplied identifier is illegal
 * @throws ParseException on an unparseable spec
 * @throws GenerationException on a generation failure
 */
final readonly class GenerationPlanner
{
    public function plan(GenerationRequest $request): GenerationPlan
    {
        if ($request->spec === null || $request->spec === '') {
            throw new PlanException('No OpenAPI spec configured. Pass --spec or set openapi-laravel.spec.');
        }

        if ($request->output === null || $request->output === '') {
            throw new PlanException('No output path configured. Pass --output or set openapi-laravel.output.path.');
        }

        // Validate operator-supplied identifiers up front, before any file is
        // planned, so a stray space or quote fails fast (C-3).
        OptionValidator::namespace('output.namespace', $request->namespace);
        OptionValidator::identifier('output.suffix', $request->suffix);
        if ($request->controllers) {
            OptionValidator::namespace('controllers.namespace', $request->controllerNamespace);
        }

        $document = (new SpecParser($request->maxBytes))->parseFile($request->spec);

        // Subset generation (issue #44): when --only-tags / --only-schemas are
        // set, resolve the selection to its transitive dependency closure once,
        // here, so the model generator and the server scaffold both restrict to
        // the same self-consistent slice. With no selection this is the "all"
        // sentinel: closure is null, nothing is filtered, output is byte-identical.
        $selection = SubsetSelection::of($request->onlyTags, $request->onlySchemas);
        $closure = null;
        $keepSchemas = null;
        if (! $selection->isAll()) {
            $closure = (new SchemaClosure)->resolve($document, $selection);
            if ($closure->hasUnknown()) {
                throw new PlanException($this->unknownSelectionMessage($closure));
            }
            $keepSchemas = $closure->schemaSet();
        }

        $generator = new ModelGenerator(new GeneratorOptions(
            $request->namespace,
            $request->suffix,
            $request->maxDepth,
            $request->enforceClosedObjects,
            $keepSchemas,
        ));
        $modelFiles = $generator->generate($document);

        $files = [];
        $target = rtrim($request->output, '/');
        foreach ($modelFiles as $file) {
            $files[] = new PlannedFile(
                $target.'/'.$file->filename(),
                $file->code,
                PlannedFile::CATEGORY_DATA,
            );
        }

        $files = [...$files, ...$this->planServer($request, $document, $generator->registry(), $closure)];

        return new GenerationPlan($files, $modelFiles === [], $generator->warnings());
    }

    /**
     * The error message for a subset flag that named a tag or schema absent from
     * the spec. A typo (or a stale flag after a spec change) is a configuration
     * error, not a silent empty slice, so the message lists exactly what did not
     * match and the planner raises a PlanException.
     */
    private function unknownSelectionMessage(ResolvedClosure $closure): string
    {
        $parts = [];
        if ($closure->unknownSchemas !== []) {
            $parts[] = 'schema(s) '.implode(', ', $closure->unknownSchemas);
        }
        if ($closure->unknownTags !== []) {
            $parts[] = 'tag(s) '.implode(', ', $closure->unknownTags);
        }

        return 'Subset selection matched nothing for '.implode(' and ', $parts)
            .'. Check --only-schemas / --only-tags against the spec (names are case-sensitive); nothing was generated.';
    }

    /**
     * @param  array<string, array{dataClass: string, writeClass: ?string, kind: 'data'|'enum'}>  $registry
     * @return list<PlannedFile>
     */
    private function planServer(GenerationRequest $request, OpenApi $document, array $registry, ?ResolvedClosure $closure): array
    {
        if (! $request->controllers && ! $request->routes) {
            return [];
        }

        if ($request->controllers && ($request->controllerPath === null || $request->controllerPath === '')) {
            throw new PlanException('No controllers path configured. Set openapi-laravel.controllers.path.');
        }

        if ($request->routes && ($request->routesPath === null || $request->routesPath === '')) {
            throw new PlanException('No routes path configured. Set openapi-laravel.routes.path.');
        }

        $serverOptions = new ServerOptions($request->controllerNamespace, $request->namespace);

        // Collect descriptors once and feed the same list to both generators so
        // controller method names and route targets can never drift apart.
        $descriptors = (new OperationCollector($serverOptions, $registry, $closure))->collect($document);

        $files = [];

        if ($request->controllers) {
            /** @var string $controllerPath */
            $controllerPath = $request->controllerPath;
            $controllerTarget = rtrim($controllerPath, '/');
            $controllerFiles = (new ControllerGenerator($serverOptions))->generate($descriptors);
            foreach ($controllerFiles as $file) {
                $files[] = new PlannedFile(
                    $controllerTarget.'/'.$file->filename(),
                    $file->code,
                    PlannedFile::CATEGORY_CONTROLLER,
                );
            }
        }

        if ($request->routes) {
            /** @var string $routesPath */
            $routesPath = $request->routesPath;
            $routeFile = (new RouteGenerator($serverOptions))->generate($descriptors);
            $files[] = new PlannedFile(
                $routesPath,
                $routeFile->code,
                PlannedFile::CATEGORY_ROUTES,
            );
        }

        return $files;
    }
}
