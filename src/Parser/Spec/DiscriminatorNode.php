<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * The Discriminator Object of a polymorphic schema (issue #104). `mapping` is
 * null when the key is absent, distinguishing "no mapping declared" from an
 * explicit empty map. `defaultMapping` is the OpenAPI 3.2 fixed field, typed
 * from day one even though the emitter does not consume it yet (issue #102).
 *
 * @internal
 */
final readonly class DiscriminatorNode
{
    /**
     * @param  array<string, string>|null  $mapping  discriminator value to schema name or `$ref`
     * @param  string|null  $defaultMapping  OpenAPI 3.2: fallback schema name or `$ref` (stub, issue #102)
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public string $propertyName,
        public ?array $mapping = null,
        public ?string $defaultMapping = null,
        public array $extensions = [],
    ) {}
}
