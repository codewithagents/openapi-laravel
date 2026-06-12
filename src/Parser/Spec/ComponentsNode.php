<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * The Components Object (issue #104). The four sections the generator consumes
 * (`schemas`, `responses`, `parameters`, `requestBodies`) are typed maps.
 * `securitySchemes` stays a raw map: the security middleware mapping
 * (issue #77) only needs scheme names and the raw scheme details, never a
 * typed scheme graph. Everything else (`headers`, `examples`, `links`,
 * `callbacks`, `pathItems`) lands in the raw `extra` bag.
 *
 * @internal
 */
final readonly class ComponentsNode
{
    /**
     * @param  array<string, SchemaNode|ReferenceNode>  $schemas
     * @param  array<string, ResponseNode|ReferenceNode>  $responses
     * @param  array<string, ParameterNode|ReferenceNode>  $parameters
     * @param  array<string, RequestBodyNode|ReferenceNode>  $requestBodies
     * @param  array<string, array<string, mixed>>  $securitySchemes  scheme name to raw scheme object
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     * @param  array<string, mixed>  $extra  untyped component sections (raw passthrough)
     */
    public function __construct(
        public array $schemas = [],
        public array $responses = [],
        public array $parameters = [],
        public array $requestBodies = [],
        public array $securitySchemes = [],
        public array $extensions = [],
        public array $extra = [],
    ) {}
}
