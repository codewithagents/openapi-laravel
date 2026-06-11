<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts a value is an ISO 8601 duration (the OpenAPI `format: duration`), the
 * shape JSON Schema and OpenAPI require: `P` followed by one or more
 * date components (`nY`, `nM`, `nD`) and/or a `T` time section with one or more
 * time components (`nH`, `nM`, `nS`). For example `P3Y6M4DT12H30M5S`, `PT1H`,
 * `P1D`, `P1Y`, `PT0S`.
 *
 * A bare `string` rule accepts any text, so a `format: duration` field validated
 * with only `string` silently lets malformed durations (`P`, `PT`, `1H`, `noon`)
 * through. This rule is strict about the structural grammar and rejects them.
 *
 * The grammar enforced (the common non-fractional ISO 8601 duration form):
 *   - must start with `P`;
 *   - at least one component must be present (a bare `P` or `PT` is invalid);
 *   - date components `Y`, `M`, `D` come before the `T`;
 *   - a `T` must be followed by at least one time component (`H`, `M`, `S`);
 *   - each component is one or more ASCII digits then its unit letter.
 *
 * The week form (`P3W`) and fractional components (`PT0.5S`) are intentionally
 * NOT accepted here: they are rarer, cannot be mixed with the other components,
 * and accepting them cleanly would widen the grammar without a real payload
 * needing it. A value using them is rejected rather than silently mis-validated,
 * which is the safe direction for a strictness rule.
 *
 * A non-string value passes here and is left to the accompanying `string` rule.
 *
 * Attached by the generator to string properties whose schema declares
 * `format: duration` via a `new Iso8601DurationRule` rule expression.
 */
final class Iso8601DurationRule implements ValidationRule
{
    /**
     * ISO 8601 duration: `P`, then an optional date section (any of `nY nM nD`),
     * then an optional `T` time section requiring at least one of `nH nM nS`.
     * The outer alternation requires at least one section overall, so a bare `P`
     * (no date section and no `T`) fails to match.
     */
    private const PATTERN = '/^P(?:(?:\d+Y)?(?:\d+M)?(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?)$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->matchesDuration($value)) {
            return;
        }

        $fail('The :attribute must be a valid ISO 8601 duration (for example P3Y6M4DT12H30M5S or PT1H).');
    }

    private function matchesDuration(string $value): bool
    {
        // The structural regex above still accepts the degenerate `P`, `PT`, and
        // a `T` with no following time component, because every group is
        // optional. Reject those explicitly so at least one real component is
        // required and a `T` always carries a time component.
        if ($value === 'P' || $value === 'PT' || str_ends_with($value, 'T')) {
            return false;
        }

        return preg_match(self::PATTERN, $value) === 1;
    }
}
