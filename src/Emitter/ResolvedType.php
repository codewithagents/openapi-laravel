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

        // A union encodes null as a trailing member ('string|int|null'); a plain
        // type uses the nullable shorthand ('?string').
        return $this->isUnion ? $this->declaration.'|null' : '?'.$this->declaration;
    }
}
