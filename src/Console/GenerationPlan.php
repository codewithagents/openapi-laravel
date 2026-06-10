<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The complete, in-memory result of planning a generation run: every file the
 * generator would write, as an absolute path plus exact content. Generate walks
 * the list and writes each file; check compares each against disk.
 *
 * `noModelSchemas` records that the spec produced no component schemas, so the
 * generate path can keep emitting its "nothing to generate" warning while still
 * planning any requested controllers/routes.
 */
final readonly class GenerationPlan
{
    /**
     * @param  list<PlannedFile>  $files
     */
    public function __construct(
        public array $files,
        public bool $noModelSchemas,
    ) {}

    /**
     * @return list<PlannedFile>
     */
    public function filesByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (PlannedFile $file): bool => $file->category === $category,
        ));
    }
}
