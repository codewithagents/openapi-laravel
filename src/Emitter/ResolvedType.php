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
    ) {}

    public function declaration(): string
    {
        return $this->nullable ? '?'.$this->declaration : $this->declaration;
    }
}
