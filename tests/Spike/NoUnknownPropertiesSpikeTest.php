<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * THROWAWAY SPIKE (issue #30). Proves the mechanism by which a generated
 * spatie/laravel-data class can REJECT unknown keys (additionalProperties:
 * false) through the real Laravel validator, before wiring it into the
 * generator. The TestCase boots a Testbench app so the Validator facade works.
 *
 * Mechanism under test: a custom ValidationRule that is
 *   - IMPLICIT (public `$implicit = true`), so Laravel runs it even when the
 *     attribute it is keyed under is absent from the payload, and
 *   - DataAwareRule, so it receives the whole payload via setData() and can
 *     compare the present keys against the declared (wire-name) allow-list.
 * It is attached under a sentinel key (one that can never be a real wire name)
 * so it never shadows a declared property and always fires.
 */
uses(TestCase::class);

/**
 * The candidate rule, hand-written here to prove the approach. The real
 * generator will emit an equivalent src/Support rule.
 */
final class SpikeNoUnknownPropertiesRule implements DataAwareRule, ValidationRule
{
    /** Laravel runs an implicit rule even when its attribute is absent. */
    public bool $implicit = true;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param list<string> $allowed the declared wire-name allow-list */
    public function __construct(private readonly array $allowed) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unknown = array_diff(array_keys($this->data), $this->allowed);

        if ($unknown !== []) {
            $fail('The :attribute contains unknown properties: '.implode(', ', $unknown).'.');
        }
    }
}

/**
 * A hand-written Data class mirroring what the generator would emit for a
 * `{ known: string }` object with `additionalProperties: false`: the declared
 * property plus the sentinel-keyed implicit rule carrying the allow-list.
 */
final class SpikeClosedData extends Data
{
    public function __construct(
        public string $known,
    ) {}

    /**
     * @return array<string, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'known' => ['required', 'string'],
            '__no_unknown_properties' => [new SpikeNoUnknownPropertiesRule(['known'])],
        ];
    }
}

it('accepts a payload with only declared keys', function () {
    SpikeClosedData::validate(['known' => 'x']);
    expect(true)->toBeTrue();
});

it('rejects a payload carrying an extra undeclared key', function () {
    SpikeClosedData::validate(['known' => 'x', 'extra' => 'y']);
})->throws(ValidationException::class);

it('still rejects an unknown key even when no declared key is present', function () {
    // The implicit rule must fire even though the sentinel attribute is absent
    // AND no declared property is present. Only `extra` is sent.
    SpikeClosedData::validate(['extra' => 'y']);
})->throws(ValidationException::class);
