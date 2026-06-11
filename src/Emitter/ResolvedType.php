<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * The resolved PHP type for a single schema, ready to render into a property
 * declaration. `declaration` is the bare type ('string', 'int', 'CustomerData',
 * 'array'); `docType` carries the richer generic for arrays
 * ('array<int, CustomerData>'); `imports` lists FQCNs the file must use;
 * `dataCollectionOf` names the element Data class when the value is a typed
 * collection.
 *
 * `isUnion` marks a native PHP union type ('string|int', 'CatData|DogData')
 * emitted for an oneOf/anyOf whose members all resolve cleanly. A union renders
 * its nullability as a trailing `|null` member, never the `?` shorthand, since
 * PHP forbids `?A|B`.
 *
 * `isMap` marks an `additionalProperties` map (`array<string, X>`). A map needs a
 * transformer so an empty map serializes as a JSON object `{}` rather than the
 * array `[]` that `json_encode([])` would otherwise emit.
 */
final readonly class ResolvedType
{
    /**
     * @param  list<string>  $imports
     */
    public function __construct(
        public string $declaration,
        public bool $nullable = false,
        public ?string $docType = null,
        public array $imports = [],
        public ?string $dataCollectionOf = null,
        public bool $isUnion = false,
        public bool $isMap = false,
    ) {}

    public function declaration(): string
    {
        if (! $this->nullable) {
            return $this->declaration;
        }

        // `mixed` already includes null: PHP fatals on `?mixed`, so never add the
        // nullable prefix to it.
        if ($this->declaration === 'mixed') {
            return 'mixed';
        }

        // A genuine multi-member union encodes null as a trailing member
        // ('string|int|null'), since PHP forbids `?A|B`. A single type (including
        // a degenerate one-member union like a `oneOf` of one scalar) uses the
        // nullable shorthand ('?string'), which the Laravel Pint preset normalizes
        // to anyway, so emitting it directly keeps the output formatter-idempotent.
        return $this->isMultiMemberUnion() ? $this->declaration.'|null' : '?'.$this->declaration;
    }

    /**
     * Whether the declaration is a genuine union of two or more distinct PHP
     * types ('string|int', 'CatData|DogData'). A degenerate one-member union (a
     * `oneOf` of a single scalar resolves with isUnion = true but a 'string'
     * declaration) is NOT a multi-member union: it must render with the `?`
     * shorthand, not a `|null` member, to stay Pint-idempotent.
     */
    public function isMultiMemberUnion(): bool
    {
        return $this->isUnion && str_contains($this->declaration, '|');
    }
}
