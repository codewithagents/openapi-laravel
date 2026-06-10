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
        {--controllers : Also generate abstract controllers (one per tag)}
        {--routes : Also generate the routes file}';

    /**
     * @var string
     */
    protected $description = 'Generate Laravel models from an OpenAPI spec.';

    public function handle(): int
    {
        $request = (new CommandRequestFactory)->fromCommand($this);

        try {
            $plan = (new GenerationPlanner)->plan($request);
        } catch (PlanException|OptionException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (ParseException|GenerationException $e) {
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

        if ($request->controllers) {
            $written = $writer->write($plan, PlannedFile::CATEGORY_CONTROLLER);
            $this->components->info(sprintf('Generated %d abstract %s into %s', count($written), count($written) === 1 ? 'controller' : 'controllers', $request->controllerPath));
        }

        if ($request->routes) {
            $written = $writer->write($plan, PlannedFile::CATEGORY_ROUTES);
            $count = count($written);
            $this->components->info(sprintf('Generated %d %s into %s', $count, $count === 1 ? 'route file' : 'route files', $request->routesPath));
        }

        return self::SUCCESS;
    }
}
