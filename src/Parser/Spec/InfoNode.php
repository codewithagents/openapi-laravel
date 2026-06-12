<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * The Info Object (issue #104). `title` and `version` are required by the spec
 * and by the parser's structural rejection, so they are non-nullable. `summary`
 * is the OpenAPI 3.1 addition. `contact` and `license` stay raw passthrough:
 * the generator does not consume them.
 *
 * @internal
 */
final readonly class InfoNode
{
    /**
     * @param  string|null  $summary  OpenAPI 3.1
     * @param  array<string, mixed>|null  $contact  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>|null  $license  raw passthrough, not consumed by the emitter
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public string $title,
        public string $version,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $termsOfService = null,
        public ?array $contact = null,
        public ?array $license = null,
        public array $extensions = [],
    ) {}
}
