<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

/**
 * Turns an out-of-memory fatal during spec parsing into a clear, actionable
 * message instead of a raw PHP fatal plus stack trace (issue #17).
 *
 * A true OOM (`Allowed memory size of N bytes exhausted`) is an E_ERROR fatal in
 * PHP: it is NOT catchable with try/catch and unwinds the whole request. The
 * only place to react to it is a shutdown function, which still runs after a
 * fatal. So we register one shutdown handler for the process, arm it around the
 * parse step (recording which spec is being parsed), and on shutdown inspect
 * `error_get_last()`. If the last error is an OOM that happened while we were
 * armed, we print guidance to STDERR; otherwise we stay silent and let normal
 * flow (or the real error) proceed.
 *
 * This cannot be exercised by a real OOM in a cheap unit test, so the message
 * construction is factored into {@see self::messageFor()} and tested directly
 * with a simulated `error_get_last()` array. See the test for the limitation.
 *
 * @internal
 */
final class MemoryGuard
{
    private static bool $registered = false;

    /**
     * The spec currently being parsed, or null when nothing is armed. Used both
     * as the "are we armed" flag and to name the offending file in the message.
     */
    private static ?string $activeSpec = null;

    /**
     * The memory_limit-style byte ceiling that was in force, surfaced in the
     * message so the operator knows what to raise.
     */
    private static ?int $maxBytes = null;

    /**
     * Arm the guard for the duration of a single parse. Idempotently registers
     * the process-wide shutdown handler on first use.
     */
    public static function arm(string $spec, int $maxBytes): void
    {
        self::$activeSpec = $spec;
        self::$maxBytes = $maxBytes;

        if (! self::$registered) {
            register_shutdown_function(self::handleShutdown(...));
            self::$registered = true;
        }
    }

    /**
     * Disarm after a successful (or cleanly-failed, non-fatal) parse, so a later
     * unrelated fatal is not misattributed to spec parsing.
     */
    public static function disarm(): void
    {
        self::$activeSpec = null;
        self::$maxBytes = null;
    }

    /**
     * Shutdown callback. Stays silent unless armed AND the last error is an OOM
     * fatal; otherwise it lets the normal fatal/flow surface unchanged.
     */
    public static function handleShutdown(): void
    {
        if (self::$activeSpec === null) {
            return;
        }

        $message = self::messageFor(error_get_last(), self::$activeSpec, self::$maxBytes);

        if ($message !== null) {
            fwrite(STDERR, $message."\n");
        }
    }

    /**
     * Build the operator-facing message for a given last-error array, or null
     * when the error is not an out-of-memory fatal (in which case the guard must
     * stay silent). Pure and side-effect free so it can be unit tested with a
     * simulated error array.
     *
     * @param  array{type: int, message: string, file: string, line: int}|null  $lastError
     */
    public static function messageFor(?array $lastError, string $spec, ?int $maxBytes): ?string
    {
        if (! self::isOutOfMemory($lastError)) {
            return null;
        }

        $limit = $maxBytes !== null
            ? sprintf(' (the --max-bytes guard allowed up to %d bytes of input)', $maxBytes)
            : '';

        return sprintf(
            'Out of memory while parsing the OpenAPI spec (%s)%s. The spec is too large to parse '
            .'within the current PHP memory_limit. Increase the PHP memory_limit (for example '
            .'`php -d memory_limit=1G ...`) or reduce the spec. Note: raising --max-bytes lets '
            .'larger specs reach the YAML parser, which needs proportionally more memory.',
            $spec,
            $limit,
        );
    }

    /**
     * Whether the last error is an out-of-memory fatal. PHP reports OOM as an
     * E_ERROR whose message starts with "Allowed memory size of".
     *
     * @param  array{type: int, message: string, file: string, line: int}|null  $lastError
     */
    private static function isOutOfMemory(?array $lastError): bool
    {
        if ($lastError === null) {
            return false;
        }

        return $lastError['type'] === E_ERROR
            && str_contains($lastError['message'], 'Allowed memory size of');
    }
}
