<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

/**
 * The opt-in Laravel-convention method naming (issue #94): maps a clean
 * RESTful (HTTP method, path) pair to the conventional Laravel controller
 * method name, or null when the operation has no conventional equivalent.
 *
 * The path classification rule is deliberately small and exact. A path is an
 * ITEM path when its last non-empty segment is a path parameter (`{name}`
 * with a non-empty name, the same `{...}` shape the rest of the collector
 * recognizes); every other path is a COLLECTION path, including the root `/`,
 * a trailing-slash path like `/pets/` (the empty trailing segment is
 * dropped), and a path with a malformed brace segment (treated as a literal).
 * Parameters in the middle of the path do not matter: `/users/{id}/pets` is a
 * collection path, `/users/{id}/pets/{petId}` is an item path.
 *
 * The mapping itself:
 *
 *   GET        collection  -> index
 *   POST       collection  -> store
 *   GET        item        -> show
 *   PUT/PATCH  item        -> update
 *   DELETE     item        -> destroy
 *
 * Everything else (POST on an item path, DELETE on a collection, PUT/PATCH on
 * a collection, HEAD/OPTIONS/TRACE anywhere) returns null: those operations
 * keep their operationId-derived name. This class only produces the
 * CANDIDATE; the per-controller ambiguity rule (two operations claiming the
 * same conventional name both fall back) lives in the OperationCollector,
 * which sees all operations of a controller at once.
 *
 * @internal
 */
final readonly class LaravelConventionNames
{
    /**
     * The conventional Laravel method name for an operation, or null when the
     * (HTTP method, path) pair has no conventional equivalent.
     *
     * @param  string  $httpMethod  lowercase HTTP method ('get', 'post', ...)
     * @param  string  $path  the path as written in the spec ('/pets/{petId}')
     */
    public static function candidate(string $httpMethod, string $path): ?string
    {
        $item = self::isItemPath($path);

        return match ($httpMethod) {
            'get' => $item ? 'show' : 'index',
            'post' => $item ? null : 'store',
            'put', 'patch' => $item ? 'update' : null,
            'delete' => $item ? 'destroy' : null,
            default => null,
        };
    }

    /**
     * An item path ends with a path-parameter segment. Empty segments are
     * dropped first, so `/pets/` classifies by `pets` (a collection) and the
     * root `/` has no segments at all (also a collection). The parameter
     * check mirrors OperationCollector::pathToken(): `{` + non-empty name +
     * `}`, so a malformed segment like `{petId` stays a literal.
     */
    private static function isItemPath(string $path): bool
    {
        $last = null;
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '') {
                $last = $segment;
            }
        }

        if ($last === null) {
            return false;
        }

        return str_starts_with($last, '{') && str_ends_with($last, '}') && strlen($last) > 2;
    }
}
