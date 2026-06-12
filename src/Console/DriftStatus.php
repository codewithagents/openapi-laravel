<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * The drift verdict for a single planned file, compared against disk.
 *
 * @internal
 */
enum DriftStatus: string
{
    /** The file on disk matches the planned content byte-for-byte. */
    case InSync = 'in-sync';

    /** No file exists at the planned path. */
    case Missing = 'missing';

    /** A file exists at the planned path but its content differs. */
    case Changed = 'changed';
}
