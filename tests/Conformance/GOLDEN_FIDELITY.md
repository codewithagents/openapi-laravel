# Golden conformance fidelity

Harness: tests/Conformance/GoldenSpecFidelityTest.php.
Generated: 2026-06-11.

This is the measured runtime answer to "how much of the comprehensive golden
conformance contract does the generated wrapper actually honor". Every row is a
named conformance schema driven through the generated validate()/from() with the
real Laravel validator: valid payloads must be accepted (and round-trip without
data loss or retyping), invalid payloads must be rejected.

**Headline:** 20 construct(s) FULLY ENFORCED, 4 tracked KNOWN-GAP, out of 24 total.

## Fully enforced

Valid payloads accepted, invalid payloads rejected, round-trips clean.

| construct | valid cases | invalid cases |
| --------- | ----------- | ------------- |
| Scalars | 2 | 3 |
| StringFormats | 1 | 9 |
| StringConstraints | 2 | 3 |
| NumericConstraints | 3 | 5 |
| StringEnum (via GadgetInput.kind) | 2 | 3 |
| FloatEnum | 2 | 1 |
| MixedTypeEnum | 4 | 1 |
| ObjectWithRequired | 2 | 3 |
| Widget | 1 | 1 |
| ErrorObject | 1 | 1 |
| AllOfWithSiblings | 1 | 2 |
| AllOfNested | 1 | 1 |
| Defaults | 2 | 1 |
| Nullability | 2 | 0 |
| ReadWriteOnly (read view) | 1 | 1 |
| ReadWriteOnly (writable view) | 1 | 0 |
| TreeNode (recursion) | 1 | 1 |
| ChainA (deep ref chain) | 2 | 0 |
| 3.0 BooleanExclusiveBounds | 1 | 2 |
| 3.0 NullableObject | 1 | 1 |

## Tracked known gaps

Constructs the generator knowingly under-enforces. The harness asserts the
CURRENT (lenient) behavior and tolerates it, but fails if the gap is silently
fixed without removing its entry, or if any new construct drifts.

- **AdditionalPropsFalse** (#30), AdditionalPropsFalseData: expected `reject`, actual `accept`. additionalProperties: false is not enforced; unknown keys are silently accepted.
- **OneOfNoDiscriminator** (#31), ExerciserData: expected `reject`, actual `accept`. An undiscriminated object union is presence-only: any object is accepted, no variant shape is enforced. The interim fix traded variant enforcement for not false-rejecting a valid non-first variant.
- **NullableMixedOneOf** (#8), NullableMixedOneOfData: expected `accept`, actual `reject`. A required oneOf that includes type:"null" resolves to a bare `required` rule on a mixed property (the #8 ?mixed fallback). Laravel `required` rejects null, so a spec-valid present-null is false-rejected. Adding `nullable` would also relax the missing-key case, so the interim fallback keeps strict presence.
- **OneOfDiscriminated (variant const discriminator)** (#disc-const), GadgetAlphaData: expected `reject`, actual `accept`. When a discriminated-union variant is validated standalone, the discriminator property (kind, declared const: alpha) is enforced presence-only (string), not pinned to its const value, so a mismatched discriminator value is accepted. Variant selection via the morph base still routes by the mapping; this only affects validating a single variant class directly.

## Observed mismatches this run

Every accept/reject disagreement the harness observed (tracked gaps included).
Any row NOT covered by a tracked known gap above fails the suite.

| # | construct | class | violates | label | expected | actual | payload |
| - | --------- | ----- | -------- | ----- | -------- | ------ | ------- |
| 1 | NullableMixedOneOf | NullableMixedOneOfData | (valid payload) | a present null satisfies the nullable oneOf member | accept | reject | `{"value":null}` |
| 2 | OneOfDiscriminated (variant GadgetAlpha) | GadgetAlphaData | discriminator.const | a variant whose discriminator const does not match its own value must be rejected | reject | accept | `{"kind":"beta","alphaField":"x"}` |
| 3 | Exerciser (maps/arrays/aliases) | ExerciserData | oneOf.no-match | an object matching no union variant must be rejected (objectUnion) | reject | accept | `{"objectUnion":{"nope":1}}` |
| 4 | AdditionalPropsFalse | AdditionalPropsFalseData | additionalProperties:false | an undeclared extra property must be rejected | reject | accept | `{"known":"x","extra":"y"}` |

