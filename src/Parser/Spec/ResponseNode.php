<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Response Object (issue #104). The spec requires `description`, but it is
 * nullable here because real-world specs omit it and the reader must stay
 * lenient. `headers` and `links` are raw passthrough: the emitter does not
 * consume them.
 *
 * @internal
 */
final readonly class ResponseNode
{
    /**
     * @param  array<string, MediaTypeNode>  $content  media type to MediaTypeNode
     * @param  array<string, mixed>|null  $headers  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>|null  $links  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public ?string $description = null,
        public array $content = [],
        public ?array $headers = null,
        public ?array $links = null,
        public array $extensions = [],
    ) {}
}
