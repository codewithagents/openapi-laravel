<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The complete, in-memory result of planning a generation run: every file the
 * generator would write, as an absolute path plus exact content. Generate walks
 * the list and writes each file; check compares each against disk.
 *
 * `noModelSchemas` records that the spec produced no data-layer files at all
 * (no component schemas AND no per-operation query/body classes), so the
 * generate path can keep emitting its "nothing to generate" warning while still
 * planning any requested controllers/routes.
 *
 * `warnings` carries non-fatal diagnostics from the model generator (for example
 * a non-standard per-property `required` key the spec used and OpenAPI ignores).
 * The generate paths print them after writing; nothing in the planned files is
 * affected. It defaults to an empty list so existing constructions keep working.
 *
 * `unsupportedCount` is the number of constructs the generator could not
 * faithfully represent (the fidelity report's entry count). The generate paths
 * print a one-line summary when it is positive and the report is enabled. It is
 * independent of whether the report file is actually planned: a disabled report
 * still has an accurate count, the command just does not print the pointer.
 *
 * @internal
 */
final readonly class GenerationPlan
{
    /**
     * @param  list<PlannedFile>  $files
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $files,
        public bool $noModelSchemas,
        public array $warnings = [],
        public int $unsupportedCount = 0,
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
