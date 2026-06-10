<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use CodeWithAgents\OpenApiLaravel\Emitter\FileWriter;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Framework-free entry point: runs the generator from CLI arguments without a
 * Laravel container, so non-Laravel projects and CI can use it via
 * vendor/bin/openapi-laravel. The Laravel artisan command and this class share
 * the same parser/generator/writer core.
 */
final class StandaloneApplication
{
    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        $options = $this->parse($argv);

        $spec = $options['spec'] ?? null;
        $output = $options['output'] ?? null;

        if ($spec === null || $output === null) {
            fwrite(STDERR, $this->usage());

            return 1;
        }

        $namespace = $options['namespace'] ?? 'App\\Data';
        $suffix = $options['suffix'] ?? 'Data';
        $maxDepth = isset($options['max-depth']) ? (int) $options['max-depth'] : 64;
        $prune = isset($options['prune']);

        try {
            $document = (new SpecParser)->parseFile($spec);
            $generator = new ModelGenerator(new GeneratorOptions($namespace, $suffix, $maxDepth));
            $files = $generator->generate($document);
        } catch (ParseException|GenerationException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }

        if ($files === []) {
            fwrite(STDOUT, "No component schemas found; nothing to generate.\n");

            return 0;
        }

        $written = (new FileWriter($output))->write($files, $prune);

        fwrite(STDOUT, sprintf("Generated %d %s into %s\n", count($written), count($written) === 1 ? 'class' : 'classes', $output));

        return 0;
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
          openapi-laravel --spec=<path> --output=<dir> [options]

        Options:
          --spec=<path>        Path to the OpenAPI document (required)
          --output=<dir>       Output directory for generated classes (required)
          --namespace=<ns>     Namespace for generated classes (default: App\Data)
          --suffix=<suffix>    Data class name suffix (default: Data)
          --max-depth=<n>      Maximum schema nesting depth (default: 64)
          --prune              Delete existing .php files in the output dir first

        TXT;
    }
}
