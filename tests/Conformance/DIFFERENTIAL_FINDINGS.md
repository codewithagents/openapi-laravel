# Differential validation findings

Oracle: tests/Conformance/DifferentialValidationTest.php (issue #23).
Generated: 2026-06-11.

Each row is a payload whose generated-validator outcome disagrees with the spec.
`expected=reject, actual=accept` means the generated rules SILENTLY ACCEPT data the spec forbids (severity High).
`expected=accept, actual=reject` means the generated rules FALSELY REJECT data the spec allows (severity High/Medium).

| # | construct | group | violates | label | expected | actual | payload |
| - | --------- | ----- | -------- | ----- | -------- | ------ | ------- |
| 1 | PetHolder | union | oneOf.no-match | matches neither variant must be rejected | reject | accept | `{"pet":{"quack":"q"}}` |

## Tracked known gaps

These constructs are documented limitations, by design or tracked in an open issue. The oracle tolerates them (they do not fail the suite) but fails if they are silently fixed without removing the entry, or if any new, unlisted construct drifts.

- **PetHolder** (#31, closed as by-design): An undiscriminated object union is presence-only by design: any object is accepted, no variant is enforced. Enforcing a variant without a discriminator would false-reject valid payloads of the other variants, so variant enforcement is deliberately traded for never rejecting valid data. Discriminated unions are validated and hydrated in all three forms (#38); add a discriminator to the spec to get variant enforcement.

