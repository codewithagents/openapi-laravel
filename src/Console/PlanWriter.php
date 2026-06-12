<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * Writes a planned generation to disk. Each PlannedFile carries its own
 * absolute path, so writing is a flat walk: ensure the parent directory exists,
 * then write the exact bytes. Because the plan encodes the exact paths and
 * content the generators produced, output stays byte-identical and
 * deterministic.
 *
 * @internal
 */
final readonly class PlanWriter
{
    /**
     * Writes the files in a category and returns the paths written, sorted.
     * When $pruneDir is given, every *.php file in that directory is deleted
     * first, so a removed schema does not leave a stale class behind (this
     * mirrors the model writer's --prune behaviour and is only used for the
     * Data output directory, never the controllers directory).
     *
     * @return list<string>
     */
    public function write(GenerationPlan $plan, string $category, ?string $pruneDir = null): array
    {
        if ($pruneDir !== null && $pruneDir !== '') {
            $this->prune($pruneDir);
        }

        $written = [];

        foreach ($plan->filesByCategory($category) as $file) {
            $directory = dirname($file->path);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($file->path, $file->content);
            $written[] = $file->path;
        }

        sort($written);

        return $written;
    }

    private function prune(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $existing = glob(rtrim($directory, '/').'/*.php') ?: [];

        foreach ($existing as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
