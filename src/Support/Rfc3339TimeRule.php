<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts a value is an RFC3339 `full-time` (the OpenAPI `format: time`), the
 * shape JSON Schema and OpenAPI require: a wall-clock time, optionally with
 * fractional seconds, and an optional timezone offset. For example `14:30:00Z`,
 * `14:30:00+02:00`, `14:30:00.123Z`, or a bare `14:30:00`.
 *
 * A bare `string` rule accepts any text, so a `format: time` field validated
 * with only `string` silently lets malformed times (`25:00:00`, `noon`, a full
 * date-time, a bare date) through. This rule is strict: it accepts only the
 * RFC3339 time forms and rejects everything else.
 *
 * The implementation mirrors Rfc3339DateTimeRule: it tries a small set of
 * explicit time formats with DateTimeImmutable::createFromFormat under a leading
 * `!` (so unspecified fields reset rather than defaulting to "now"). The `H`
 * token enforces a 00-23 hour and `i`/`s` enforce 00-59, so an out-of-range
 * component like `25:00:00` fails. `P` matches both `Z` and a numeric `+HH:MM`
 * offset; the no-offset variant covers a bare local time. The `v` (milliseconds)
 * and `u` (microseconds) tokens both parse any fractional-second precision, so
 * `.1`, `.123`, and `.123456` all pass.
 *
 * RFC3339 permits a leap second (`:60`); PHP rejects it, which is an acceptable
 * narrow over-strictness (no real payload carries a leap second).
 *
 * A non-string value passes here and is left to the accompanying `string` rule.
 *
 * Attached by the generator to string properties whose schema declares
 * `format: time` via a `new Rfc3339TimeRule` rule expression.
 */
final class Rfc3339TimeRule implements ValidationRule
{
    /**
     * Explicit RFC3339 time formats, tried in order. `P` matches both `Z` and a
     * numeric offset like `+02:00`. `v`/`u` match fractional seconds. The
     * offset-less variants accept a bare local time (`14:30:00`).
     *
     * @var list<string>
     */
    private const FORMATS = [
        'H:i:sP',
        'H:i:s.vP',
        'H:i:s.uP',
        'H:i:s',
        'H:i:s.v',
        'H:i:s.u',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->matchesRfc3339Time($value)) {
            return;
        }

        $fail('The :attribute must be a valid RFC3339 time (for example 14:30:00Z).');
    }

    private function matchesRfc3339Time(string $value): bool
    {
        foreach (self::FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($parsed instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();

                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return true;
                }
            }
        }

        return false;
    }
}
