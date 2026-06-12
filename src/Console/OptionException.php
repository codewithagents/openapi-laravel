<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

use RuntimeException;

/**
 * Thrown when an operator-supplied option (namespace, suffix, prefix) fails
 * validation before any file is written.
 *
 * @internal
 */
final class OptionException extends RuntimeException {}
