<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use Illuminate\Console\Command;

final class GenerateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'openapi:generate {--spec= : Path to the OpenAPI document} {--output= : Output directory for generated classes}';

    /**
     * @var string
     */
    protected $description = 'Generate Laravel models from an OpenAPI spec.';

    public function handle(): int
    {
        $this->components->error('openapi:generate is not implemented yet.');

        return self::FAILURE;
    }
}
