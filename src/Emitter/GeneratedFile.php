<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * A single emitted PHP file: the class it defines and its rendered source.
 * The relative path is derived from the class name so writers stay trivial.
 * Under the tag-grouped data layout (issue #93) a file may carry a single
 * subdirectory segment; the flat default keeps it null, so every existing
 * path stays byte-identical.
 *
 * @internal
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $className,
        public string $code,
        public ?string $directory = null,
    ) {}

    public function filename(): string
    {
        return ($this->directory !== null ? $this->directory.'/' : '').$this->className.'.php';
    }
}
