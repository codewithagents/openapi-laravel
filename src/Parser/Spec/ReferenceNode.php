<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A `$ref` reference object (issue #104). Unlike cebe's Reference, this is a
 * dumb value object: it never resolves anything, it only carries the raw
 * pointer string (e.g. `#/components/schemas/Pet`) plus the OpenAPI 3.1
 * sibling `summary` / `description` overrides. Resolution stays a consumer
 * concern, exactly how the emitter already treats references today.
 *
 * @internal
 */
final readonly class ReferenceNode
{
    /**
     * @param  string  $ref  the raw `$ref` pointer string, never normalized
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public string $ref,
        public ?string $summary = null,
        public ?string $description = null,
        public array $extensions = [],
    ) {}

    /**
     * The raw pointer string. Replacement for cebe's `Reference::getReference()`.
     */
    public function pointer(): string
    {
        return $this->ref;
    }
}
