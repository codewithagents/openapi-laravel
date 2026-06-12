<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Request Body Object (issue #104). `content` maps the media type string
 * (e.g. `application/json`, `multipart/form-data`) to its MediaTypeNode;
 * `required` is nullable so an absent key stays distinguishable from an
 * explicit `required: false`.
 *
 * @internal
 */
final readonly class RequestBodyNode
{
    /**
     * @param  array<string, MediaTypeNode>  $content  media type to MediaTypeNode
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public array $content = [],
        public ?string $description = null,
        public ?bool $required = null,
        public array $extensions = [],
    ) {}
}
