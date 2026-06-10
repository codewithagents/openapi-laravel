<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The comparison result for one planned file: its path, the verdict, and the
 * expected (planned) versus on-disk content so a diff can be rendered without a
 * second disk read.
 */
final readonly class DriftEntry
{
    public function __construct(
        public string $path,
        public DriftStatus $status,
        public string $expected,
        public string $actual,
    ) {}

    public function isDrifted(): bool
    {
        return $this->status !== DriftStatus::InSync;
    }
}
