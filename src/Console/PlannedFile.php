<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * One file the generator would write: an absolute target path and its exact
 * byte content. The planner computes these in memory without touching disk, so
 * `openapi:generate` can write them and `openapi:check` can compare them against
 * what is already on disk.
 *
 * The category names which generator produced the file (Data class, inlined
 * runtime support class, abstract controller, routes file, or a one-time
 * concrete controller stub). Drift checks only ever see generator-owned
 * categories: stubs (issue #78) are planned solely for the scaffold command,
 * which never overwrites an existing file, so a user's concrete controllers
 * are never flagged or touched.
 *
 * @internal
 */
final readonly class PlannedFile
{
    public const CATEGORY_DATA = 'data';

    public const CATEGORY_SUPPORT = 'support';

    public const CATEGORY_CONTROLLER = 'controller';

    public const CATEGORY_ROUTES = 'routes';

    public const CATEGORY_STUB = 'stub';

    /**
     * @param  self::CATEGORY_*  $category
     */
    public function __construct(
        public string $path,
        public string $content,
        public string $category,
    ) {}
}
