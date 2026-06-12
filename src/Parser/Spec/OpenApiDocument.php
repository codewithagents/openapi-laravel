<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * The root of the parsed OpenAPI document (issue #104), the replacement for
 * cebe's OpenApi object. A pure read-only graph: no reference resolution, no
 * writers, no lazy state. `security` keeps the same presence semantics as on
 * the operation level (null = absent, [] = explicit empty). `warnings` carries
 * the parser's best-effort notices (e.g. the 3.2 construct scanner from
 * issue #103) so they travel with the document instead of a side channel.
 * `tags` and `servers` stay raw passthrough: the generator groups by operation
 * tags, never by the root tag list.
 *
 * @internal
 */
final readonly class OpenApiDocument
{
    /**
     * @param  string  $openapi  the raw version string, e.g. `3.1.0`
     * @param  array<string, PathItemNode>  $paths  path template to path item
     * @param  array<string, PathItemNode|ReferenceNode>  $webhooks  OpenAPI 3.1
     * @param  list<SecurityRequirementNode>|null  $security  null = absent key, [] = explicit empty
     * @param  list<array<string, mixed>>|null  $tags  raw passthrough, not consumed by the emitter
     * @param  list<array<string, mixed>>|null  $servers  raw passthrough, not consumed by the emitter
     * @param  list<string>  $warnings  parser notices (e.g. 3.2 best-effort constructs, issue #103)
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public string $openapi,
        public InfoNode $info,
        public array $paths = [],
        public ?ComponentsNode $components = null,
        public array $webhooks = [],
        public ?array $security = null,
        public ?array $tags = null,
        public ?array $servers = null,
        public array $warnings = [],
        public array $extensions = [],
    ) {}
}
