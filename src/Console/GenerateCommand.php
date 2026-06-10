<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use CodeWithAgents\OpenApiLaravel\Emitter\FileWriter;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\ParseException;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;
use Illuminate\Console\Command;

final class GenerateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'openapi:generate
        {--spec= : Path to the OpenAPI document (defaults to config)}
        {--output= : Output directory for generated classes (defaults to config)}
        {--prune : Delete existing .php files in the output directory first}';

    /**
     * @var string
     */
    protected $description = 'Generate Laravel models from an OpenAPI spec.';

    public function handle(): int
    {
        $spec = $this->stringOption('spec') ?? $this->configString('openapi-laravel.spec');
        $output = $this->stringOption('output') ?? $this->configString('openapi-laravel.output.path');
        $namespace = $this->configString('openapi-laravel.output.namespace') ?? 'App\\Data';
        $suffix = $this->configString('openapi-laravel.output.suffix') ?? 'Data';
        $depth = config('openapi-laravel.max_depth');
        $maxDepth = is_int($depth) ? $depth : 64;
        $prune = (bool) $this->option('prune') || (bool) config('openapi-laravel.output.prune');

        if ($spec === null || $spec === '') {
            $this->components->error('No OpenAPI spec configured. Pass --spec or set openapi-laravel.spec.');

            return self::FAILURE;
        }

        if ($output === null || $output === '') {
            $this->components->error('No output path configured. Pass --output or set openapi-laravel.output.path.');

            return self::FAILURE;
        }

        try {
            $document = (new SpecParser)->parseFile($spec);
            $generator = new ModelGenerator(new GeneratorOptions($namespace, $suffix, $maxDepth));
            $files = $generator->generate($document);
        } catch (ParseException|GenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($files === []) {
            $this->components->warn('No component schemas found; nothing to generate.');

            return self::SUCCESS;
        }

        $written = (new FileWriter($output))->write($files, $prune);

        $this->components->info(sprintf('Generated %d %s into %s', count($written), count($written) === 1 ? 'class' : 'classes', $output));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
