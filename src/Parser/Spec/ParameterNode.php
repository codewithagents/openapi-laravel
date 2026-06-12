<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Parameter Object (issue #104). `name` and `in` are the only fields the
 * spec requires; everything else is nullable so absence stays representable.
 * The exotic `content` form of a parameter is carried as a typed media-type
 * map even though the emitter currently only consumes the `schema` form.
 *
 * @internal
 */
final readonly class ParameterNode
{
    /**
     * @param  string  $in  one of `query`, `path`, `header`, `cookie`
     * @param  array<string, MediaTypeNode>|null  $content  media type to MediaTypeNode
     * @param  array<string, mixed>|null  $examples  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public string $name,
        public string $in,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public ?bool $allowEmptyValue = null,
        public ?string $style = null,
        public ?bool $explode = null,
        public ?bool $allowReserved = null,
        public SchemaNode|ReferenceNode|null $schema = null,
        public mixed $example = null,
        public ?array $examples = null,
        public ?array $content = null,
        public array $extensions = [],
    ) {}
}
