<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use Illuminate\Console\Command;

final class GenerateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'openapi:generate
        {--spec= : Path to the OpenAPI document (defaults to config)}
        {--output= : Output directory for generated classes (defaults to config)}
        {--namespace= : Namespace for generated classes (overrides config)}
        {--prune : Delete existing .php files in the output directory first}
        {--controllers : Generate abstract controllers even when disabled in config (default: on)}
        {--no-controllers : Skip the abstract controllers}
        {--routes : Generate the routes file even when disabled in config (default: on)}
        {--no-routes : Skip the routes file}
        {--enforce-closed-objects : Reject unknown keys for schemas with additionalProperties: false (default: on)}
        {--no-enforce-closed-objects : Accept unknown keys even for additionalProperties: false schemas}
        {--only-tags= : Generate only operations carrying these comma-separated tags, plus their schema closure}
        {--only-schemas= : Generate only these comma-separated component schemas, plus their dependency closure}';

    /**
     * @var string
     */
    protected $description = 'Generate Laravel models, controllers, and routes from an OpenAPI spec.';

    public function handle(): int
    {
        try {
            $request = (new CommandRequestFactory)->fromCommand($this);
            $plan = (new GenerationPlanner)->plan($request);
        } catch (OptionException $e) {
            // Invalid options (conflicting flags, illegal identifiers) are a
            // configuration error and exit 2 on every surface, matching
            // openapi:check and the standalone binary.
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (PlanException|ParseException|GenerationException $e) {
            // A planning, spec-parse, or generation failure is a runtime failure
            // (exit 1), distinct from the configuration error above (exit 2).
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $prune = (bool) $this->option('prune') || (bool) config('openapi-laravel.output.prune');
        $writer = new PlanWriter;

        if ($plan->noModelSchemas) {
            $this->components->warn('No component schemas found; nothing to generate.');
        } else {
            $written = $writer->write($plan, PlannedFile::CATEGORY_DATA, $prune ? $request->output : null);
            $this->components->info(sprintf('Generated %d %s into %s', count($written), count($written) === 1 ? 'class' : 'classes', $request->output));
        }

        // The inlined runtime support classes (issue #40) live in a `Support/`
        // subdirectory of the Data output. Prune it too on --prune so a support
        // class that a spec stopped using does not linger as a stale, drift-
        // flagging file. The output path is non-null here: the planner rejects a
        // null output before any plan is produced.
        if ($request->output !== null && $request->output !== '') {
            $supportDir = rtrim($request->output, '/').'/Support';
            $written = $writer->write($plan, PlannedFile::CATEGORY_SUPPORT, $prune ? $supportDir : null);
            if ($written !== []) {
                $this->components->info(sprintf('Generated %d support %s into %s', count($written), count($written) === 1 ? 'class' : 'classes', $supportDir));
            }
        }

        if ($request->controllers) {
            $written = $writer->write($plan, PlannedFile::CATEGORY_CONTROLLER);
            $this->components->info(sprintf('Generated %d abstract %s into %s', count($written), count($written) === 1 ? 'controller' : 'controllers', $request->controllerPath));
        }

        if ($request->routes) {
            $written = $writer->write($plan, PlannedFile::CATEGORY_ROUTES);
            $count = count($written);
            $this->components->info(sprintf('Generated %d %s into %s', $count, $count === 1 ? 'route file' : 'route files', $request->routesPath));
        }

        // Non-fatal diagnostics (e.g. a non-standard per-property `required` key
        // the spec used and OpenAPI ignores) go to stderr so they never pollute
        // captured stdout, and do not change the success exit code.
        foreach ($plan->warnings as $warning) {
            $this->output->getErrorStyle()->warning($warning);
        }

        return self::SUCCESS;
    }
}
