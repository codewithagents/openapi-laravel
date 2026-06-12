<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * A Security Requirement Object (issue #104). The object has no fixed fields,
 * only dynamic scheme-name keys, which is exactly why the resolver needed a
 * `getSerializableData()` escape with cebe (issue #77). Here the map is
 * a plain typed array: scheme name to its (possibly empty) scope list.
 *
 * An empty `schemes` map models the spec's `{}` entry inside a `security`
 * list; the explicit-empty `security: []` (public override) is represented one
 * level up, by an empty list of SecurityRequirementNode versus null for an
 * absent `security` key.
 *
 * @internal
 */
final readonly class SecurityRequirementNode
{
    /**
     * @param  array<string, list<string>>  $schemes  scheme name to scope list
     */
    public function __construct(
        public array $schemes = [],
    ) {}

    /**
     * @return list<string>
     */
    public function schemeNames(): array
    {
        return array_keys($this->schemes);
    }
}
