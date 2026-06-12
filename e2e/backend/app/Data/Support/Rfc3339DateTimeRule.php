<?php

declare(strict_types=1);

namespace App\Data\Support;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts a value is an RFC3339 `date-time` (the OpenAPI `format: date-time`),
 * the shape JSON Schema and OpenAPI require: a full timestamp with a time and a
 * timezone offset, for example `2024-01-15T10:30:00Z` or
 * `2024-01-15T10:30:00+02:00`, optionally with fractional seconds
 * (`2024-01-15T10:30:00.123Z`).
 *
 * Laravel's bare `date` rule accepts a date-only string like `2024-01-15` and a
 * great many non-RFC3339 inputs, so a date-time field validated with `date`
 * silently lets malformed timestamps through. This rule is strict: it accepts
 * only the RFC3339 timestamp forms and rejects a bare date or free text.
 *
 * The implementation tries a small set of explicit RFC3339 formats with
 * DateTimeImmutable::createFromFormat under a leading `!` (so unspecified fields
 * reset rather than defaulting to "now"), covering the `Z` (UTC) and numeric
 * `+HH:MM` offset variants with and without fractional seconds. A value that
 * matches none of them fails. The `v` (milliseconds) and `u` (microseconds)
 * tokens both parse any fractional-second precision, so `.1`, `.123`, and
 * `.123456` all pass.
 *
 * Attached by the generator to string properties whose schema declares
 * `format: date-time` via a `new Rfc3339DateTimeRule` rule expression.
 */
final class Rfc3339DateTimeRule implements ValidationRule
{
    /**
     * Explicit RFC3339 timestamp formats, tried in order. `P` matches both `Z`
     * and a numeric offset like `+02:00`. `v`/`u` match fractional seconds.
     *
     * @var list<string>
     */
    private const FORMATS = [
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.vP',
        'Y-m-d\TH:i:s.uP',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->matchesRfc3339($value)) {
            return;
        }

        $fail('The :attribute must be a valid RFC3339 date-time (for example 2024-01-15T10:30:00Z).');
    }

    private function matchesRfc3339(string $value): bool
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
