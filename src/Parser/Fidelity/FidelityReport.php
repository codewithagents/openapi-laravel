<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Fidelity;

/**
 * The consolidation point for every construct the generator could not faithfully
 * represent in the generated code. Entries accumulate during the run (the parser
 * scans the document, the planner folds in the diagnostics the emitters already
 * surface), are deduped on (pointer, construct), and serialize to a byte-stable
 * `openapi-laravel.unsupported.json` artifact.
 *
 * The artifact is machine-readable and part of the drift-checked output set, so
 * it carries nothing run-variant (no timestamp, no version, no absolute path):
 * the same spec in always produces byte-identical JSON out, exactly like the
 * generated PHP. A user may gitignore it; the config opt-out then removes it from
 * both generation and the checked set so the drift gate stays consistent.
 *
 * @internal
 */
final class FidelityReport
{
    public const FILENAME = 'openapi-laravel.unsupported.json';

    public const GENERATOR = 'openapi-laravel';

    /**
     * Keyed by FidelityEntry::key() so an identical finding seen twice (the read
     * and write variant of one schema, two scan passes) is stored once. Insertion
     * order is irrelevant: serialization sorts deterministically.
     *
     * @var array<string, FidelityEntry>
     */
    private array $entries = [];

    public function add(FidelityEntry $entry): void
    {
        $this->entries[$entry->key()] = $entry;
    }

    /**
     * Convenience for the common call shape, so a scan site does not build the
     * value object by hand. Severity stays the default "correctness".
     */
    public function record(string $pointer, string $location, string $construct, string $impact): void
    {
        $this->add(new FidelityEntry($pointer, $location, $construct, $impact));
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * The entries sorted deterministically by pointer, then construct, so the
     * ordering never churns between runs of the same spec.
     *
     * @return list<FidelityEntry>
     */
    public function entries(): array
    {
        $entries = array_values($this->entries);

        usort($entries, static function (FidelityEntry $a, FidelityEntry $b): int {
            return [$a->pointer, $a->construct] <=> [$b->pointer, $b->construct];
        });

        return $entries;
    }

    /**
     * The exact JSON shape of the artifact: a fixed generator name, then the
     * sorted entries (or an empty array when nothing was unsupported). Pretty
     * printed with a trailing newline, matching the project's other written
     * artifacts; an empty entry list still serializes "unsupported": [] so the
     * file's presence is stable whether or not the spec had gaps.
     */
    public function toJson(): string
    {
        $payload = [
            'generator' => self::GENERATOR,
            'unsupported' => array_map(
                static fn (FidelityEntry $entry): array => $entry->toArray(),
                $this->entries(),
            ),
        ];

        // JSON_PRETTY_PRINT uses 4-space indentation; the slash and unicode flags
        // keep pointers like "#/paths/~1pets" readable instead of escaped. The
        // trailing newline matches the generated PHP files (a clean POSIX line
        // ending) so an editor that trims/adds one does not look like drift.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
