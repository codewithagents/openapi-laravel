# Golden conformance fidelity

Harness: tests/Conformance/GoldenSpecFidelityTest.php.
Generated: 2026-06-11.

This is the measured runtime answer to "how much of the comprehensive golden
conformance contract does the generated wrapper actually honor". Every row is a
named conformance schema driven through the generated validate()/from() with the
real Laravel validator: valid payloads must be accepted (and round-trip without
data loss or retyping), invalid payloads must be rejected.

**Headline:** 25 construct(s) FULLY ENFORCED, 1 tracked KNOWN-GAP, out of 26 total.

## Fully enforced

Valid payloads accepted, invalid payloads rejected, round-trips clean.

| construct | valid cases | invalid cases |
| --------- | ----------- | ------------- |
| Scalars | 2 | 3 |
| StringFormats | 6 | 14 |
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
| NullableMixedOneOf | 2 | 1 |
| ReadWriteOnly (read view) | 1 | 1 |
| ReadWriteOnly (writable view) | 1 | 0 |
| OneOfDiscriminated (variant GadgetAlpha) | 1 | 2 |
| InlineDiscriminatedUnion (inline form #38) | 2 | 4 |
| Vehicle (allOf-inheritance form #38) | 3 | 3 |
| TreeNode (recursion) | 1 | 1 |
| ChainA (deep ref chain) | 2 | 0 |
| AdditionalPropsFalse | 1 | 1 |
| 3.0 BooleanExclusiveBounds | 1 | 2 |
| 3.0 NullableObject | 1 | 1 |

## Tracked known gaps

Constructs the generator knowingly under-enforces. The harness asserts the
CURRENT (lenient) behavior and tolerates it, but fails if the gap is silently
fixed without removing its entry, or if any new construct drifts.

- **OneOfNoDiscriminator** (#31), ExerciserData: expected `reject`, actual `accept`. An undiscriminated object union is presence-only: any object is accepted, no variant shape is enforced. The interim fix traded variant enforcement for not false-rejecting a valid non-first variant.

## Observed mismatches this run

Every accept/reject disagreement the harness observed (tracked gaps included).
Any row NOT covered by a tracked known gap above fails the suite.

| # | construct | class | violates | label | expected | actual | payload |
| - | --------- | ----- | -------- | ----- | -------- | ------ | ------- |
| 1 | Exerciser (maps/arrays/aliases) | ExerciserData | oneOf.no-match | an object matching no union variant must be rejected (objectUnion) | reject | accept | `{"objectUnion":{"nope":1}}` |

