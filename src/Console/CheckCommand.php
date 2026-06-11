<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use Illuminate\Console\Command;

/**
 * Verifies that the committed generated code is in sync with the spec. It plans
 * what `openapi:generate` would write (the same config and flags), then compares
 * every planned file against disk byte-for-byte. Drift is structurally
 * impossible to miss: a missing or changed generator-owned file fails the
 * command, so a CI step can gate every merge on it.
 *
 * Exit codes: 0 in sync, 1 drift detected, 2 configuration/spec error.
 */
final class CheckCommand extends Command
{
    private const EXIT_IN_SYNC = 0;

    private const EXIT_DRIFT = 1;

    private const EXIT_ERROR = 2;

    /**
     * @var string
     */
    protected $signature = 'openapi:check
        {--spec= : Path to the OpenAPI document (defaults to config)}
        {--output= : Output directory for generated classes (defaults to config)}
        {--namespace= : Namespace for generated classes (overrides config)}
        {--controllers : Check the abstract controllers even when disabled in config (default: on)}
        {--no-controllers : Skip the abstract controllers}
        {--routes : Check the routes file even when disabled in config (default: on)}
        {--no-routes : Skip the routes file}
        {--enforce-closed-objects : Reject unknown keys for schemas with additionalProperties: false (default: on)}
        {--no-enforce-closed-objects : Accept unknown keys even for additionalProperties: false schemas}
        {--only-tags= : Check only operations carrying these comma-separated tags, plus their schema closure}
        {--only-schemas= : Check only these comma-separated component schemas, plus their dependency closure}
        {--diff : Print a unified diff for each changed file}';

    /**
     * @var string
     */
    protected $description = 'Verify the committed generated code is in sync with the spec.';

    public function handle(): int
    {
        try {
            $request = (new CommandRequestFactory)->fromCommand($this);
            $plan = (new GenerationPlanner)->plan($request);
        } catch (PlanException|OptionException $e) {
            $this->components->error($e->getMessage());

            return self::EXIT_ERROR;
        } catch (ParseException|GenerationException $e) {
            $this->components->error($e->getMessage());

            return self::EXIT_ERROR;
        }

        $entries = (new DriftChecker)->check($plan);
        $drifted = array_values(array_filter($entries, static fn (DriftEntry $entry): bool => $entry->isDrifted()));

        if ($drifted === []) {
            $this->components->info('Generated code is in sync with the spec.');

            return self::EXIT_IN_SYNC;
        }

        $this->components->error(sprintf('Drift detected in %d file(s):', count($drifted)));

        $diff = (bool) $this->option('diff');
        $lineDiff = new LineDiff;

        foreach ($drifted as $entry) {
            $tag = $entry->status === DriftStatus::Missing ? 'missing' : 'changed';
            $this->line(sprintf('  [%s] %s', $tag, $entry->path));

            if ($diff && $entry->status === DriftStatus::Changed) {
                foreach ($lineDiff->diff($entry->expected, $entry->actual) as $line) {
                    $this->line('    '.$line);
                }
            }
        }

        return self::EXIT_DRIFT;
    }
}
