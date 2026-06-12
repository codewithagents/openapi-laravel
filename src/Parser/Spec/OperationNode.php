<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * An Operation Object (issue #104). `security` carries the three-way presence
 * semantics issue #77 depends on: null means the key is absent (global
 * security applies), an empty list means the explicit `security: []` public
 * override, and a non-empty list overrides the global requirements.
 * `callbacks` and `servers` stay raw passthrough: the emitter does not consume
 * them.
 *
 * @internal
 */
final readonly class OperationNode
{
    /**
     * @param  list<string>  $tags
     * @param  list<ParameterNode|ReferenceNode>  $parameters
     * @param  list<SecurityRequirementNode>|null  $security  null = absent key, [] = explicit public override
     * @param  array<string, mixed>|null  $callbacks  raw passthrough, not consumed by the emitter
     * @param  list<array<string, mixed>>|null  $servers  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public ?string $operationId = null,
        public array $tags = [],
        public ?string $summary = null,
        public ?string $description = null,
        public array $parameters = [],
        public RequestBodyNode|ReferenceNode|null $requestBody = null,
        public ?ResponsesNode $responses = null,
        public ?bool $deprecated = null,
        public ?array $security = null,
        public ?array $callbacks = null,
        public ?array $servers = null,
        public array $extensions = [],
    ) {}
}
