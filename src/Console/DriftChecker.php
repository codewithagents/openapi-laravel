<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * Compares a generation plan against what is currently on disk. A planned file
 * is drifted when it is missing or when its on-disk content differs by even a
 * single byte. Because generation is deterministic, this is an exact-match
 * comparison: no normalization is needed or wanted.
 *
 * Only the generator-owned files in the plan are checked, so a user's
 * hand-written concrete controllers and any unrelated files are never flagged.
 */
final readonly class DriftChecker
{
    /**
     * @return list<DriftEntry> one entry per planned file, in plan order
     */
    public function check(GenerationPlan $plan): array
    {
        $entries = [];

        foreach ($plan->files as $file) {
            if (! is_file($file->path)) {
                $entries[] = new DriftEntry($file->path, DriftStatus::Missing, $file->content, '');

                continue;
            }

            $actual = file_get_contents($file->path);
            $actual = $actual === false ? '' : $actual;

            $status = $actual === $file->content ? DriftStatus::InSync : DriftStatus::Changed;
            $entries[] = new DriftEntry($file->path, $status, $file->content, $actual);
        }

        return $entries;
    }
}
