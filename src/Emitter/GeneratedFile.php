<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * A single emitted PHP file: the class it defines and its rendered source.
 * The relative path is derived from the class name so writers stay trivial.
 */
final class GeneratedFile
{
    public function __construct(
        public readonly string $className,
        public readonly string $code,
    ) {}

    public function filename(): string
    {
        return $this->className.'.php';
    }
}
