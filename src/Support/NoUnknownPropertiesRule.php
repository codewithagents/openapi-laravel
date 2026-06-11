<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects keys outside a declared allow-list, mirroring an OpenAPI schema that
 * declares `additionalProperties: false` (a closed object shape). Laravel has no
 * native "no unknown keys" rule, so the generator emits this one whenever the
 * `enforceClosedObjects` generator option is on (the default).
 *
 * Two interface choices make it work as a single payload-wide assertion rather
 * than a per-property one:
 *  - It is IMPLICIT (the public `$implicit` flag, which Laravel's
 *    InvokableValidationRule reads): an implicit rule runs even when the
 *    attribute it is keyed under is absent from the payload. The generator keys
 *    it under a sentinel attribute that is never a real wire name, so the rule
 *    fires exactly once per object and never shadows a declared property.
 *  - It is a DataAwareRule, so Laravel hands it the whole payload via setData().
 *    The keyed `$value` is therefore ignored; the rule inspects every top-level
 *    key of the payload and fails when any falls outside the allow-list.
 *
 * The allow-list is the set of declared wire (input) names, the same names the
 * generated `rules()` keys its per-property entries under. Both an object with
 * named properties and a pure map declared `additionalProperties: false` are
 * covered: a pure map has an empty allow-list, so any key is rejected.
 *
 * A schema may also declare `patternProperties`: keys matching one of those
 * patterns are legal even under `additionalProperties: false`, so the rule
 * carries a second, pattern allow-list of delimited PCRE patterns. A key that
 * matches any of them is never reported as unknown (its value schema is not
 * validated; only key admission is enforced).
 *
 * Forward-compatibility note: enforcing a closed shape rejects a payload that
 * carries a field the spec has not declared yet, so a producer that adds a field
 * before the consumer regenerates would break. A consumer that needs the
 * lenient, forward-compatible behavior opts out via
 * `--no-enforce-closed-objects`, which suppresses this rule entirely.
 */
final class NoUnknownPropertiesRule implements DataAwareRule, ValidationRule
{
    /**
     * Marks the rule as implicit so Laravel runs it even when the sentinel
     * attribute it is keyed under is not present in the payload. Without this
     * the closed-shape check would silently skip when the sentinel is absent,
     * which is always.
     */
    public bool $implicit = true;

    /**
     * The full payload under validation, injected by Laravel via setData().
     *
     * @var array<array-key, mixed>
     */
    private array $data = [];

    /**
     * @param  list<string>  $allowed  the declared wire (input) names that are permitted
     * @param  list<string>  $allowedPatterns  delimited PCRE patterns (from `patternProperties`) whose matching keys are also permitted
     */
    public function __construct(
        private readonly array $allowed,
        private readonly array $allowedPatterns = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unknown = [];

        // Compare against string keys only: the payload is a JSON object, so its
        // keys are strings (PHP may have coerced numeric ones to int).
        foreach (array_map('strval', array_keys($this->data)) as $key) {
            if (in_array($key, $this->allowed, true) || $this->matchesAllowedPattern($key)) {
                continue;
            }
            $unknown[] = $key;
        }

        if ($unknown !== []) {
            $fail('The :attribute may not contain unknown properties: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * Whether a key matches one of the `patternProperties` allow-patterns. The
     * generator only emits patterns it verified compile as PCRE, so a runtime
     * compile failure (preg_match() returning false) is defensive territory: an
     * uncompilable pattern must never cause a false rejection, so a failure
     * counts as a match (fail open, mirroring the project principle that
     * false-rejecting valid data is worse than under-validating).
     */
    private function matchesAllowedPattern(string $key): bool
    {
        foreach ($this->allowedPatterns as $pattern) {
            if (@preg_match($pattern, $key) !== 0) {
                return true;
            }
        }

        return false;
    }
}
