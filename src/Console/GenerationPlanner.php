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
 *
 * @internal
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

        // The server scaffold runs BEFORE the support files are collected: the
        // per-operation query Data classes (issue #63) are emitted while the
        // operations are collected, and their rules may reference support
        // classes that must land in the inlined set below.
        [$serverFiles, $serverWarnings] = $this->planServer($request, $document, $generator, $closure);

        // The per-operation query Data classes (issue #63) live next to the
        // model Data classes: same namespace, same output directory, same
        // drift-checked CATEGORY_DATA bucket.
        foreach ($generator->queryFiles() as $queryFile) {
            $files[] = new PlannedFile(
                $target.'/'.$queryFile->filename(),
                $queryFile->code,
                PlannedFile::CATEGORY_DATA,
            );
        }

        // Inline the runtime support classes the generated Data files reference
        // into the consumer's own `<output>/Support/` directory (issue #40), so
        // generated output is self-contained with no runtime dependency on the
        // generator package. Only the classes this spec used are emitted, and
        // they are owned, drift-checked output like the Data classes themselves.
        foreach ($generator->supportFiles() as $supportFile) {
            $files[] = new PlannedFile(
                $target.'/Support/'.$supportFile->filename(),
                $supportFile->code,
                PlannedFile::CATEGORY_SUPPORT,
            );
        }

        $files = [...$files, ...$serverFiles];

        // One merged, sorted diagnostics channel: the model generator's
        // warnings (including skipped query parameters) plus the operation
        // collector's (header/cookie parameters).
        $warnings = array_values(array_unique([...$generator->warnings(), ...$serverWarnings]));
        sort($warnings);

        return new GenerationPlan($files, $modelFiles === [], $warnings);
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
     * Plan the server scaffold (controllers + routes). The model generator is
     * handed through so the operation collector can emit the per-operation
     * query Data classes (issue #63) with the exact rules pipeline the model
     * classes used; the planner collects those files via queryFiles() after
     * this returns.
     *
     * The collector runs even when controllers AND routes are both disabled:
     * the query Data classes are data-layer output (CATEGORY_DATA, same
     * namespace and directory as the model classes), so a model-only run must
     * still produce them, and the check path must still see them. Only the
     * controller/route FILE planning is gated on the scaffold flags. In that
     * scaffold-disabled case the collector's own warnings (header/cookie
     * parameters the scaffold would not type) are dropped, because they
     * describe scaffold output that was not requested; the query-skip
     * warnings reach the channel through the model generator regardless.
     *
     * @return array{0: list<PlannedFile>, 1: list<string>} planned files, collector warnings
     */
    private function planServer(GenerationRequest $request, OpenApi $document, ModelGenerator $generator, ?ResolvedClosure $closure): array
    {
        if ($request->controllers && ($request->controllerPath === null || $request->controllerPath === '')) {
            throw new PlanException('No controllers path configured. Set openapi-laravel.controllers.path.');
        }

        if ($request->routes && ($request->routesPath === null || $request->routesPath === '')) {
            throw new PlanException('No routes path configured. Set openapi-laravel.routes.path.');
        }

        $serverOptions = new ServerOptions(
            controllerNamespace: $request->controllerNamespace,
            dataNamespace: $request->namespace,
            routeMiddleware: $request->routesMiddleware,
            routePrefix: $request->routesPrefix,
        );

        // Collect descriptors once and feed the same list to both generators so
        // controller method names and route targets can never drift apart.
        $collector = new OperationCollector($serverOptions, $generator->registry(), $closure, $generator);
        $descriptors = $collector->collect($document);

        if (! $request->controllers && ! $request->routes) {
            return [[], []];
        }

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

        return [$files, $collector->warnings()];
    }
}
