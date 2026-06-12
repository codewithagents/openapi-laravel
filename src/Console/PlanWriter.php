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

    /**
     * Writes only the files in a category that do NOT yet exist on disk, and
     * returns the paths created and the paths skipped, each sorted. This is
     * the one-time semantics of the scaffold command (issue #78): a stub whose
     * file already exists belongs to the user and is never overwritten.
     *
     * @return array{0: list<string>, 1: list<string>} created paths, skipped paths
     */
    public function writeMissing(GenerationPlan $plan, string $category): array
    {
        $created = [];
        $skipped = [];

        foreach ($plan->filesByCategory($category) as $file) {
            if (is_file($file->path)) {
                $skipped[] = $file->path;

                continue;
            }

            $directory = dirname($file->path);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($file->path, $file->content);
            $created[] = $file->path;
        }

        sort($created);
        sort($skipped);

        return [$created, $skipped];
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
