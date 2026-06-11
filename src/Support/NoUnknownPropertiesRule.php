<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects keys outside a declared allow-list, mirroring an OpenAPI schema that
 * declares `additionalProperties: false` (a closed object shape). Laravel has no
 * native "no unknown keys" rule, so the generator emits this one, opt in, under
 * the `enforceClosedObjects` generator option.
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
 * Forward-compatibility note: enforcing a closed shape rejects a payload that
 * carries a field the spec has not declared yet, so a producer that adds a field
 * before the consumer regenerates would break. That is why the generator leaves
 * this OFF by default and only emits the rule when the operator opts in.
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
     */
    public function __construct(private readonly array $allowed) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Compare against string keys only: the payload is a JSON object, so its
        // keys are strings. array_diff stringifies, which is fine here.
        $unknown = array_values(array_diff(array_map('strval', array_keys($this->data)), $this->allowed));

        if ($unknown !== []) {
            $fail('The :attribute may not contain unknown properties: '.implode(', ', $unknown).'.');
        }
    }
}
