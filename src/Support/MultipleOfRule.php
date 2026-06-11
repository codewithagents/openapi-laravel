<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts a numeric value is an integer multiple of a divisor, mirroring the
 * OpenAPI `multipleOf` keyword. Laravel has no native `multiple_of` rule.
 *
 * The check is done in integer space when both the value and the divisor are
 * whole numbers, so a value like 6 against `multipleOf: 3` is exact. When either
 * side is fractional, the value divided by the divisor must round to a whole
 * number within a small epsilon: this keeps `0.3 / 0.1` (which is 2.9999... in
 * IEEE-754) from being wrongly rejected while still rejecting genuine
 * non-multiples. A non-numeric value passes here and is left to the `numeric`/
 * `integer` rule that always accompanies it.
 *
 * Attached by the generator to numeric properties whose schema carries a
 * `multipleOf` via a `new MultipleOfRule(N)` rule expression.
 */
final readonly class MultipleOfRule implements ValidationRule
{
    public function __construct(private int|float $divisor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            // Not a number: the accompanying numeric/integer rule owns this case.
            return;
        }

        $number = (float) $value;
        $divisor = (float) $this->divisor;

        if ($divisor === 0.0) {
            // A zero divisor is meaningless; never divide by zero, accept.
            return;
        }

        $quotient = $number / $divisor;
        $rounded = round($quotient);

        // Exact for integers, epsilon-tolerant for the IEEE-754 fractional case.
        if (abs($quotient - $rounded) > 1e-9 * max(1.0, abs($quotient))) {
            $fail('The :attribute must be a multiple of '.$this->numberLabel($this->divisor).'.');
        }
    }

    private function numberLabel(int|float $divisor): string
    {
        return is_int($divisor) ? (string) $divisor : rtrim(rtrim(sprintf('%.10F', $divisor), '0'), '.');
    }
}
