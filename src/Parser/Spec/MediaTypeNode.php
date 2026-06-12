<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Media Type Object, one entry of a `content` map (issue #104). `encoding`
 * and `examples` stay raw arrays: the emitter does not consume them today
 * (multipart reads `contentMediaType` on the schema, not `encoding.contentType`,
 * a documented residual of issue #75). `itemSchema` is the OpenAPI 3.2 fixed
 * field for streaming media types, typed from day one (issue #102).
 *
 * @internal
 */
final readonly class MediaTypeNode
{
    /**
     * @param  array<string, mixed>|null  $examples  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>|null  $encoding  raw passthrough, not consumed by the emitter
     * @param  SchemaNode|ReferenceNode|null  $itemSchema  OpenAPI 3.2: per-item schema of a streaming body (stub, issue #102)
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public SchemaNode|ReferenceNode|null $schema = null,
        public mixed $example = null,
        public ?array $examples = null,
        public ?array $encoding = null,
        public SchemaNode|ReferenceNode|null $itemSchema = null,
        public array $extensions = [],
    ) {}
}
