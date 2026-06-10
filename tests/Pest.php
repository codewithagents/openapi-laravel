<?php

declare(strict_types=1);
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;

/*
 * Feature tests boot a Testbench Laravel app (service provider, artisan command).
 * Unit tests (Parser, Naming, Emitter) run as plain PHP, no container needed.
 */
uses(TestCase::class)->in('Feature');
