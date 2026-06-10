<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\FileWriter;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
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
        {--prune : Delete existing .php files in the output directory first}
        {--controllers : Also generate abstract controllers (one per tag)}
        {--routes : Also generate the routes file}';

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
        } else {
            $written = (new FileWriter($output))->write($files, $prune);
            $this->components->info(sprintf('Generated %d %s into %s', count($written), count($written) === 1 ? 'class' : 'classes', $output));
        }

        $this->generateServer($document, $generator->registry(), $namespace);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{dataClass: string, writeClass: ?string, kind: 'data'|'enum'}>  $registry
     */
    private function generateServer(OpenApi $document, array $registry, string $dataNamespace): void
    {
        $controllers = (bool) $this->option('controllers') || (bool) config('openapi-laravel.controllers.enabled');
        $routes = (bool) $this->option('routes') || (bool) config('openapi-laravel.routes.enabled');

        if (! $controllers && ! $routes) {
            return;
        }

        $controllerNamespace = $this->configString('openapi-laravel.controllers.namespace') ?? 'App\\Http\\Controllers\\Api';
        $serverOptions = new ServerOptions($controllerNamespace, $dataNamespace);

        if ($controllers) {
            $controllerPath = $this->configString('openapi-laravel.controllers.path');
            if ($controllerPath === null || $controllerPath === '') {
                $this->components->error('No controllers path configured. Set openapi-laravel.controllers.path.');
            } else {
                $controllerFiles = (new ControllerGenerator($serverOptions, $registry))->generate($document);
                // Never prune: only ever (over)write the Abstract* files, leaving
                // the user's concrete controllers untouched.
                $writtenControllers = (new FileWriter($controllerPath))->write($controllerFiles, false);
                $this->components->info(sprintf('Generated %d abstract %s into %s', count($writtenControllers), count($writtenControllers) === 1 ? 'controller' : 'controllers', $controllerPath));
            }
        }

        if ($routes) {
            $routesPath = $this->configString('openapi-laravel.routes.path');
            if ($routesPath === null || $routesPath === '') {
                $this->components->error('No routes path configured. Set openapi-laravel.routes.path.');
            } else {
                $descriptors = (new OperationCollector($serverOptions, $registry))->collect($document);
                $routeFile = (new RouteGenerator($serverOptions))->generate($descriptors);
                $this->writeRoutesFile($routesPath, $routeFile->code);
                $this->components->info(sprintf('Generated %d %s into %s', count($descriptors), count($descriptors) === 1 ? 'route' : 'routes', $routesPath));
            }
        }
    }

    private function writeRoutesFile(string $path, string $code): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $code);
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
