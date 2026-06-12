<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use cebe\openapi\spec\Operation;
use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;

/**
 * The single naming point for the opt-in tag-grouped data layout (issue #93):
 * which directory/namespace group an operation's tag maps to. Shared by the
 * attribution walk (SchemaClosure), the model generator (per-operation query
 * and body classes), and the operation collector, so the three can never
 * disagree about where a tag's classes live.
 *
 * The rules, deliberately mirroring the controller grouping:
 *
 *  - An operation belongs to exactly one group: the StudlyCaps form of its
 *    FIRST tag, the same tag that names its controller.
 *  - An operation without tags belongs to the pseudo-group 'Untagged',
 *    matching the UntaggedController the scaffold generates for it.
 *  - The group name 'Support' is reserved: the inlined runtime support classes
 *    own the `Support/` subdirectory and the `\Support` subnamespace (issue
 *    #40), so a tag that normalizes to 'Support' maps to the flat root
 *    instead of colliding with them.
 *
 * @internal
 */
final class TagGroups
{
    /**
     * The Support subdirectory/subnamespace is owned by the inlined runtime
     * support classes (issue #40); no tag group may claim it.
     */
    private const RESERVED_GROUP = 'Support';

    /**
     * The pseudo-tag for operations without tags, matching the controller
     * grouping's 'Untagged' fallback.
     */
    public const UNTAGGED = 'Untagged';

    /**
     * The directory/namespace group for a raw spec tag, or null when the tag
     * cannot own a group (it normalizes to the reserved 'Support' name) and
     * its classes stay at the flat root.
     */
    public static function forTag(string $tag): ?string
    {
        $group = PhpIdentifier::toClassName($tag);

        return $group === self::RESERVED_GROUP ? null : $group;
    }

    /**
     * The group for an operation: its first non-blank tag, normalized, or the
     * 'Untagged' pseudo-group. The first-tag rule is the controller-naming
     * rule, so the data layout always mirrors the controller layout.
     */
    public static function forOperation(Operation $operation): ?string
    {
        return self::forTag(self::firstTag($operation));
    }

    /**
     * The first non-blank tag of an operation, or 'Untagged'. Mirrors the
     * operation collector's controller naming exactly.
     */
    public static function firstTag(Operation $operation): string
    {
        $tags = $operation->tags;

        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    return $tag;
                }
            }
        }

        return self::UNTAGGED;
    }
}
