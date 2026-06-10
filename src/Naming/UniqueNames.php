<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Naming;

/**
 * Allocates names that are unique within a single generation run. A clashing
 * candidate gets a numeric suffix (_2, _3, ...) until a free slot is found.
 * Deterministic: the same sequence of requests always yields the same names.
 */
final class UniqueNames
{
    /**
     * @var array<string, true>
     */
    private array $used = [];

    public function reserve(string $candidate): string
    {
        if (! isset($this->used[$candidate])) {
            $this->used[$candidate] = true;

            return $candidate;
        }

        $counter = 2;
        while (isset($this->used[$candidate.'_'.$counter])) {
            $counter++;
        }

        $unique = $candidate.'_'.$counter;
        $this->used[$unique] = true;

        return $unique;
    }

    public function taken(string $candidate): bool
    {
        return isset($this->used[$candidate]);
    }
}
