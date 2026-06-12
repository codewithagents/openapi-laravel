<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Naming;

/**
 * Allocates names that are unique within a single generation run. A clashing
 * candidate gets a numeric suffix (_2, _3, ...) until a free slot is found.
 * Deterministic: the same sequence of requests always yields the same names.
 *
 * Case sensitivity is a constructor choice (issue #108), because the right
 * answer differs by what is being named:
 *   - CLASS names (`caseInsensitive: true`): PHP class names are themselves
 *     case-insensitive, and generated filenames derive from them, so `FOO` and
 *     `Foo` cannot coexist. On a case-insensitive filesystem (macOS APFS
 *     default, Windows NTFS) `FOOData.php` and `FooData.php` are the SAME path,
 *     and the second write silently clobbers the first, losing a class and
 *     breaking the output. Folding the uniqueness check forces the second onto
 *     the suffix path (`Foo` -> `Foo_2`), so the files stay distinct.
 *   - PROPERTY, parameter, and enum-case names (the default, case-SENSITIVE):
 *     these are case-sensitive identifiers in PHP, so two object properties or
 *     enum values differing only by case are legitimately distinct and must
 *     NOT be folded together (folding would needlessly rename one and corrupt
 *     #[MapName] round-tripping intent). They never become filenames either.
 * Either way the first claimant keeps its exact casing; a clash takes the
 * suffix path with its own original casing preserved. Folding uses byte-wise
 * strtolower, which is exact for these names: every candidate has already
 * passed through PhpIdentifier and is plain ASCII. The result is deterministic
 * and independent of the host filesystem.
 *
 * The optional constructor argument pre-reserves names that no allocation may
 * take. The model emitter uses this to block the framework short-names it always
 * imports into generated files (Data, DataCollection, Optional, ...): a schema
 * named `Data` would otherwise emit `final class Data extends Data {}`, a fatal
 * "Cannot redeclare class". Pre-reserving forces such a schema onto the normal
 * suffix path (Data -> Data_2), keeping the imported base class unshadowed; in
 * the case-insensitive mode a case variant (`DATA`) collides with the imported
 * `Data` alias just the same.
 *
 * @internal
 */
final class UniqueNames
{
    /**
     * Allocated and pre-reserved names, keyed by their lookup form (the name
     * itself when case-sensitive, its case-folded form when case-insensitive).
     *
     * @var array<string, true>
     */
    private array $used = [];

    /**
     * @param  list<string>  $reserved  names that no allocation may return
     * @param  bool  $caseInsensitive  fold the uniqueness check to lower case (for class names whose
     *                                 filenames collide on a case-insensitive filesystem, issue #108)
     */
    public function __construct(array $reserved = [], private readonly bool $caseInsensitive = false)
    {
        foreach ($reserved as $name) {
            $this->used[$this->key($name)] = true;
        }
    }

    public function reserve(string $candidate): string
    {
        if (! isset($this->used[$this->key($candidate)])) {
            $this->used[$this->key($candidate)] = true;

            return $candidate;
        }

        $counter = 2;
        while (isset($this->used[$this->key($candidate.'_'.$counter)])) {
            $counter++;
        }

        $unique = $candidate.'_'.$counter;
        $this->used[$this->key($unique)] = true;

        return $unique;
    }

    public function taken(string $candidate): bool
    {
        return isset($this->used[$this->key($candidate)]);
    }

    /**
     * The lookup key for a name: case-folded when this allocator is
     * case-insensitive, the name verbatim otherwise.
     */
    private function key(string $name): string
    {
        return $this->caseInsensitive ? strtolower($name) : $name;
    }
}
