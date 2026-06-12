<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use RuntimeException;

/**
 * Thrown by the GenerationPlanner when the request is misconfigured: a missing
 * spec, a missing output path, or a requested server target without a path. It
 * signals a configuration error (as opposed to a successful plan), so callers
 * can map it to the dedicated config-error exit code.
 *
 * @internal
 */
final class PlanException extends RuntimeException {}
