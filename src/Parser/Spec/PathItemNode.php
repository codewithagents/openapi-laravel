<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Path Item Object (issue #104). The eight fixed HTTP methods are typed
 * properties, plus the two OpenAPI 3.2 fixed fields, typed from day one
 * (issue #102): the `query` method and the `additionalOperations` map for
 * non-standard methods. A path-level `$ref` is kept as its raw pointer string
 * (no resolution, same policy as ReferenceNode).
 *
 * @internal
 */
final readonly class PathItemNode
{
    /**
     * @param  string|null  $ref  raw `$ref` pointer of a referenced path item, never resolved
     * @param  list<ParameterNode|ReferenceNode>  $parameters
     * @param  OperationNode|null  $query  OpenAPI 3.2: the QUERY method (stub, issue #102)
     * @param  array<string, OperationNode>|null  $additionalOperations  OpenAPI 3.2: custom method name to operation (stub, issue #102)
     * @param  list<array<string, mixed>>|null  $servers  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?OperationNode $get = null,
        public ?OperationNode $put = null,
        public ?OperationNode $post = null,
        public ?OperationNode $delete = null,
        public ?OperationNode $options = null,
        public ?OperationNode $head = null,
        public ?OperationNode $patch = null,
        public ?OperationNode $trace = null,
        public ?OperationNode $query = null,
        public ?array $additionalOperations = null,
        public array $parameters = [],
        public ?string $ref = null,
        public ?array $servers = null,
        public array $extensions = [],
    ) {}

    /**
     * All declared operations keyed by lowercase HTTP method, fixed methods
     * first in spec order, then `additionalOperations` entries. Replacement
     * for cebe's `PathItem::getOperations()`.
     *
     * @return array<string, OperationNode>
     */
    public function operations(): array
    {
        $fixed = [
            'get' => $this->get,
            'put' => $this->put,
            'post' => $this->post,
            'delete' => $this->delete,
            'options' => $this->options,
            'head' => $this->head,
            'patch' => $this->patch,
            'trace' => $this->trace,
            'query' => $this->query,
        ];

        $operations = [];
        foreach ($fixed as $method => $operation) {
            if ($operation instanceof OperationNode) {
                $operations[$method] = $operation;
            }
        }

        foreach ($this->additionalOperations ?? [] as $method => $operation) {
            $operations[strtolower($method)] = $operation;
        }

        return $operations;
    }
}
