<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;

/**
 * Framework-free entry point: runs the generator from CLI arguments without a
 * Laravel container, so non-Laravel projects and CI can use it via
 * vendor/bin/openapi-laravel. The Laravel artisan commands and this class share
 * the same parser/generator/writer core (GenerationPlanner), so generate and
 * check can never compute a different file set than each other.
 *
 * Subcommands:
 *   openapi-laravel [generate] --spec=... --output=...   write the generated files
 *   openapi-laravel check --spec=... --output=...        verify disk matches the spec
 *
 * `generate` is the default when no subcommand is given, preserving the
 * historical `openapi-laravel --spec=... --output=...` invocation.
 */
final class StandaloneApplication
{
    private const EXIT_OK = 0;

    private const EXIT_DRIFT = 1;

    private const EXIT_ERROR = 2;

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        [$subcommand, $argv] = $this->splitSubcommand($argv);

        return $subcommand === 'check' ? $this->runCheck($argv) : $this->runGenerate($argv);
    }

    /**
     * @param  list<string>  $argv
     */
    private function runGenerate(array $argv): int
    {
        $options = $this->parse($argv);

        try {
            $request = $this->buildRequest($options);
            $plan = (new GenerationPlanner)->plan($request);
        } catch (PlanException|OptionException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        } catch (ParseException|GenerationException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }

        $writer = new PlanWriter;
        $prune = isset($options['prune']);

        if ($plan->noModelSchemas) {
            fwrite(STDOUT, "No component schemas found; nothing to generate.\n");
        } else {
            $written = $writer->write($plan, PlannedFile::CATEGORY_DATA, $prune ? $request->output : null);
            fwrite(STDOUT, sprintf("Generated %d %s into %s\n", count($written), count($written) === 1 ? 'class' : 'classes', $request->output));
        }

        if ($request->controllers) {
            $written = $writer->write($plan, PlannedFile::CATEGORY_CONTROLLER);
            fwrite(STDOUT, sprintf("Generated %d abstract %s into %s\n", count($written), count($written) === 1 ? 'controller' : 'controllers', $request->controllerPath));
        }

        if ($request->routes) {
            $written = $writer->write($plan, PlannedFile::CATEGORY_ROUTES);
            $count = count($written);
            fwrite(STDOUT, sprintf("Generated %d %s into %s\n", $count, $count === 1 ? 'route file' : 'route files', $request->routesPath));
        }

        // Non-fatal diagnostics (e.g. a non-standard per-property `required` key
        // the spec used and OpenAPI ignores) go to stderr so stdout stays clean
        // for tooling, and they do not change the success exit code.
        foreach ($plan->warnings as $warning) {
            fwrite(STDERR, 'Warning: '.$warning."\n");
        }

        return self::EXIT_OK;
    }

    /**
     * @param  list<string>  $argv
     */
    private function runCheck(array $argv): int
    {
        $options = $this->parse($argv);

        try {
            $request = $this->buildRequest($options);
            $plan = (new GenerationPlanner)->plan($request);
        } catch (PlanException|OptionException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return self::EXIT_ERROR;
        } catch (ParseException|GenerationException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return self::EXIT_ERROR;
        }

        $entries = (new DriftChecker)->check($plan);
        $drifted = array_values(array_filter($entries, static fn (DriftEntry $entry): bool => $entry->isDrifted()));

        if ($drifted === []) {
            fwrite(STDOUT, "Generated code is in sync with the spec.\n");

            return self::EXIT_OK;
        }

        fwrite(STDOUT, sprintf("Drift detected in %d file(s):\n", count($drifted)));

        $diff = isset($options['diff']);
        $lineDiff = new LineDiff;

        foreach ($drifted as $entry) {
            $tag = $entry->status === DriftStatus::Missing ? 'missing' : 'changed';
            fwrite(STDOUT, sprintf("  [%s] %s\n", $tag, $entry->path));

            if ($diff && $entry->status === DriftStatus::Changed) {
                foreach ($lineDiff->diff($entry->expected, $entry->actual) as $line) {
                    fwrite(STDOUT, '    '.$line."\n");
                }
            }
        }

        return self::EXIT_DRIFT;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function buildRequest(array $options): GenerationRequest
    {
        $spec = $options['spec'] ?? null;
        $output = $options['output'] ?? null;

        if ($spec === null || $output === null) {
            throw new PlanException(trim($this->usage()));
        }

        $namespace = $options['namespace'] ?? 'App\\Data';
        $suffix = $options['suffix'] ?? 'Data';
        $maxDepth = isset($options['max-depth']) ? (int) $options['max-depth'] : 64;
        $maxBytes = isset($options['max-bytes']) ? (int) $options['max-bytes'] : null;

        $controllerOutput = $options['controller-output'] ?? null;
        $routesOutput = $options['routes-output'] ?? null;
        $controllers = isset($options['controllers']) || $controllerOutput !== null;
        $routes = isset($options['routes']) || $routesOutput !== null;
        $controllerNamespace = $options['controller-namespace'] ?? 'App\\Http\\Controllers\\Api';

        if ($controllers && ($controllerOutput === null || $controllerOutput === '')) {
            throw new PlanException('--controllers requires --controller-output=<dir>.');
        }

        if ($routes && ($routesOutput === null || $routesOutput === '')) {
            throw new PlanException('--routes requires --routes-output=<file>.');
        }

        return new GenerationRequest(
            $spec,
            $output,
            $namespace,
            $suffix,
            $maxDepth,
            $maxBytes,
            $controllers,
            $controllerOutput,
            $controllerNamespace,
            $routes,
            $routesOutput,
        );
    }

    /**
     * Peels an optional leading subcommand off argv (after the program name),
     * returning the subcommand (or null) and the argv with it removed so the
     * option parser sees the same shape regardless of which subcommand ran.
     *
     * @param  list<string>  $argv
     * @return array{0: ?string, 1: list<string>}
     */
    private function splitSubcommand(array $argv): array
    {
        $program = $argv[0] ?? 'openapi-laravel';
        $rest = array_slice($argv, 1);

        if (isset($rest[0]) && ($rest[0] === 'check' || $rest[0] === 'generate')) {
            $subcommand = $rest[0];
            $rest = array_slice($rest, 1);

            return [$subcommand, array_values([$program, ...$rest])];
        }

        return [null, array_values([$program, ...$rest])];
    }

    /**
     * @param  list<string>  $argv
     * @return array<string, string>
     */
    private function parse(array $argv): array
    {
        $options = [];

        foreach (array_slice($argv, 1) as $argument) {
            if (! str_starts_with($argument, '--')) {
                continue;
            }

            $body = substr($argument, 2);
            $position = strpos($body, '=');

            if ($position === false) {
                $options[$body] = '1';
            } else {
                $options[substr($body, 0, $position)] = substr($body, $position + 1);
            }
        }

        return $options;
    }

    private function usage(): string
    {
        return <<<'TXT'
        openapi-laravel - generate Laravel models from an OpenAPI spec

        Usage:
          openapi-laravel [generate] --spec=<path> --output=<dir> [options]
          openapi-laravel check --spec=<path> --output=<dir> [options]

        Commands:
          generate             Write the generated files (default)
          check                Verify the files on disk match the spec (CI drift gate)

        Options:
          --spec=<path>        Path to the OpenAPI document (required)
          --output=<dir>       Output directory for generated classes (required)
          --namespace=<ns>     Namespace for generated classes (default: App\Data)
          --suffix=<suffix>    Data class name suffix (default: Data)
          --max-depth=<n>      Maximum schema nesting depth (default: 64)
          --prune              Delete existing .php files in the output dir first (generate only)
          --diff               Print a unified diff for each changed file (check only)

        Server scaffold (optional):
          --controllers                  Generate/check abstract controllers (one per tag)
          --controller-output=<dir>      Where the abstract controllers live
          --controller-namespace=<ns>    Controller namespace (default: App\Http\Controllers\Api)
          --routes                       Generate/check the routes file
          --routes-output=<file>         Where the routes file lives

        Exit codes:
          0  success / in sync
          1  drift detected (check) or a generate error
          2  configuration or spec error (check)

        TXT;
    }
}
