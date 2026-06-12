<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Shared `$ref` pointer and union-member helpers for the emitter layer.
 *
 * Every emitter that walks the spec graph needs to answer the same two
 * questions: which component a local `#/components/...` pointer targets, and
 * which members a `oneOf`/`anyOf` schema combines. The answers were duplicated
 * across ModelGenerator, DiscriminatorRegistry, SchemaClosure, and the server
 * OperationCollector (issue #109); this helper is the single home.
 *
 * @internal
 */
final class SchemaPointer
{
    /**
     * The component-schema name a local `#/components/schemas/<Name>` pointer
     * targets, or null for an external or differently shaped pointer.
     */
    public static function refName(string $pointer): ?string
    {
        return self::componentName($pointer, 'schemas');
    }

    /**
     * The component name a local `#/components/<section>/<Name>` pointer
     * targets, or null for an external or differently shaped pointer.
     */
    public static function componentName(string $pointer, string $section): ?string
    {
        if (! str_starts_with($pointer, '#/components/'.$section.'/')) {
            return null;
        }

        $parts = explode('/', $pointer);
        $last = end($parts);

        return $last === '' ? null : $last;
    }

    /**
     * The combined member list of a `oneOf`/`anyOf` schema, in source order.
     * Both keywords are unioned: a schema rarely uses both, but if it does the
     * members compose into one type union (oneOf members first, then anyOf).
     *
     * @return list<SchemaNode|ReferenceNode>
     */
    public static function unionMembers(SchemaNode $schema): array
    {
        $members = [];

        foreach ([$schema->oneOf, $schema->anyOf] as $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $member) {
                $members[] = $member;
            }
        }

        return $members;
    }
}
