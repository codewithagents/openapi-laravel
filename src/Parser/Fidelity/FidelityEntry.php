<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Fidelity;

/**
 * One construct in the spec the generator could NOT faithfully represent in the
 * generated code, and whose dropping changes the CORRECTNESS or runtime
 * behavior of that code (a silently lost value, an unenforced constraint, a
 * presence-only union). Pure metadata losses that never reach the generated
 * code (examples, externalDocs, server lists, Link objects) are deliberately
 * not recorded: they would only add noise without describing a behavior gap.
 *
 * Every field is a plain string so the entry serializes directly into the
 * fidelity report's JSON. Nothing run-variant lives here (no timestamps, no
 * absolute paths), so the same spec always produces the same entries and the
 * report stays byte-stable across runs for the drift gate.
 *
 * @internal
 */
final readonly class FidelityEntry
{
    /**
     * @param  string  $pointer  RFC 6901 JSON pointer into the spec ('~1' encodes a literal '/')
     * @param  string  $location  short human label, e.g. "GET /pets, query parameter 'tags'"
     * @param  string  $construct  the unsupported construct, e.g. "repeated-key array query parameter"
     * @param  string  $impact  one line on what silently goes wrong in the generated code
     * @param  string  $severity  always "correctness" for now
     */
    public function __construct(
        public string $pointer,
        public string $location,
        public string $construct,
        public string $impact,
        public string $severity = 'correctness',
    ) {}

    /**
     * The dedupe key: two entries that name the same pointer AND construct are
     * the same finding (e.g. the read and write variant of one schema reach the
     * same property), so the report keeps one. Location and impact never differ
     * for a fixed (pointer, construct) pair, so they are not part of the key.
     */
    public function key(): string
    {
        return $this->pointer."\0".$this->construct;
    }

    /**
     * @return array{pointer: string, location: string, construct: string, impact: string, severity: string}
     */
    public function toArray(): array
    {
        return [
            'pointer' => $this->pointer,
            'location' => $this->location,
            'construct' => $this->construct,
            'impact' => $this->impact,
            'severity' => $this->severity,
        ];
    }
}
