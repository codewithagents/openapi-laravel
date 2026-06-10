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
 */
final class ResolvedType
{
    /**
     * @param  list<string>  $imports
     */
    public function __construct(
        public readonly string $declaration,
        public readonly bool $nullable = false,
        public readonly ?string $docType = null,
        public readonly array $imports = [],
        public readonly ?string $dataCollectionOf = null,
        public readonly bool $isUnion = false,
    ) {}

    public function declaration(): string
    {
        if (! $this->nullable) {
            return $this->declaration;
        }

        // A union encodes null as a trailing member ('string|int|null'); a plain
        // type uses the nullable shorthand ('?string').
        return $this->isUnion ? $this->declaration.'|null' : '?'.$this->declaration;
    }
}
