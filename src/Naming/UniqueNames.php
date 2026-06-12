<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Naming;

/**
 * Allocates names that are unique within a single generation run. A clashing
 * candidate gets a numeric suffix (_2, _3, ...) until a free slot is found.
 * Deterministic: the same sequence of requests always yields the same names.
 *
 * The optional constructor argument pre-reserves names that no allocation may
 * take. The model emitter uses this to block the framework short-names it always
 * imports into generated files (Data, DataCollection, Optional, ...): a schema
 * named `Data` would otherwise emit `final class Data extends Data {}`, a fatal
 * "Cannot redeclare class". Pre-reserving forces such a schema onto the normal
 * suffix path (Data -> Data_2), keeping the imported base class unshadowed.
 *
 * @internal
 */
final class UniqueNames
{
    /**
     * @var array<string, true>
     */
    private array $used = [];

    /**
     * @param  list<string>  $reserved  names that no allocation may return
     */
    public function __construct(array $reserved = [])
    {
        foreach ($reserved as $name) {
            $this->used[$name] = true;
        }
    }

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
