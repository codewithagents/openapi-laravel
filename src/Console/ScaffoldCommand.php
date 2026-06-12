<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use Illuminate\Console\Command;

/**
 * One-time concrete controller stub scaffolding (issue #78). The generated
 * routes file references concrete controller classes (PetController) that
 * extend the generated abstracts (AbstractPetController); until they exist, a
 * fresh app fatals on the first request. This command writes one stub per
 * concrete controller, with every abstract method implemented as a
 * `throw new LogicException(...)` placeholder, into the same directory and
 * namespace as the abstracts, exactly where the routes file expects them.
 *
 * The stubs are user-owned from the moment they are written: a stub whose
 * file already exists is SKIPPED, never overwritten, and `openapi:check`
 * never inspects them. The plan comes from the same GenerationPlanner that
 * generate and check share, so the stub list always matches the abstract
 * controllers and routes of the same flags and config.
 *
 * Exit codes mirror openapi:generate: 0 success, 1 plan/spec/generation
 * failure, 2 invalid options.
 */
final class ScaffoldCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'openapi:scaffold
        {--spec= : Path to the OpenAPI document (defaults to config)}
        {--output= : Output directory for generated classes (defaults to config)}
        {--namespace= : Namespace for generated classes (overrides config)}
        {--controllers : Scaffold even when controllers are disabled in config (default: on)}
        {--no-controllers : Rejected: stubs extend the abstract controllers}
        {--routes : Plan with the routes file enabled (default: on)}
        {--no-routes : Plan with the routes file disabled}
        {--enforce-closed-objects : Reject unknown keys for schemas with additionalProperties: false (default: on)}
        {--no-enforce-closed-objects : Accept unknown keys even for additionalProperties: false schemas}
        {--only-tags= : Scaffold only operations carrying these comma-separated tags, plus their schema closure}
        {--only-schemas= : Scaffold only these comma-separated component schemas, plus their dependency closure}
        {--exclude-path-prefix=* : Drop every operation whose path starts with this prefix (repeatable, never comma-split)}';

    /**
     * @var string
     */
    protected $description = 'Scaffold one-time concrete controller stubs extending the generated abstract controllers.';

    public function handle(): int
    {
        try {
            $request = (new CommandRequestFactory)->fromCommand($this, stubs: true);
            $plan = (new GenerationPlanner)->plan($request);
        } catch (OptionException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (PlanException|ParseException|GenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        [$created, $skipped] = (new PlanWriter)->writeMissing($plan, PlannedFile::CATEGORY_STUB);

        if ($created === [] && $skipped === []) {
            $this->components->warn('No controller stubs to scaffold: no operations were planned (the spec declares none, or every one was filtered out).');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Scaffolded %d controller %s into %s',
            count($created),
            count($created) === 1 ? 'stub' : 'stubs',
            $request->controllerPath,
        ));

        if ($skipped !== []) {
            $this->components->warn(sprintf(
                'Skipped %d existing %s (stubs are generated once and never overwritten): %s',
                count($skipped),
                count($skipped) === 1 ? 'file' : 'files',
                implode(', ', array_map(static fn (string $path): string => basename($path), $skipped)),
            ));
        }

        return self::SUCCESS;
    }
}
