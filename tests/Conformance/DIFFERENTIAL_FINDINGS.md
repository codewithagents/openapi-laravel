# Differential validation findings

Oracle: tests/Conformance/DifferentialValidationTest.php (issue #23).
Generated: 2026-06-11.

Each row is a payload whose generated-validator outcome disagrees with the spec.
`expected=reject, actual=accept` means the generated rules SILENTLY ACCEPT data the spec forbids (severity High).
`expected=accept, actual=reject` means the generated rules FALSELY REJECT data the spec allows (severity High/Medium).

| # | construct | group | violates | label | expected | actual | payload |
| - | --------- | ----- | -------- | ----- | -------- | ------ | ------- |
| 1 | ObjAddlPropsFalse | object | additionalProperties:false | extra undeclared property must be rejected | reject | accept | `{"known":"x","extra":"y"}` |
| 2 | PetHolder | union | (valid payload) | matches Dog variant | accept | reject | `{"pet":{"bark":"woof"}}` |

## Tracked known gaps

These constructs are documented limitations with an open issue. The oracle tolerates them (they do not fail the suite) but fails if they are silently fixed without removing the entry, or if any new, unlisted construct drifts.

- **ObjAddlPropsFalse** (#30): additionalProperties: false is not enforced; unknown keys are accepted.
- **PetHolder** (#31): Undiscriminated oneOf object-union false-rejects non-first variants (1.0.0 hydration work).

