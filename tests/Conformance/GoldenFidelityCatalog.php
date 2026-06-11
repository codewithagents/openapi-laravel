<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Conformance;

/**
 * A single runtime-fidelity case over the golden conformance spec.
 *
 * Unlike the differential catalog (which builds one minimal schema per
 * constraint), every case here targets a NAMED schema that the conformance
 * fixture already defines, by its generated class short-name. The fidelity
 * harness generates the whole conformance document once, then for each case:
 *
 *   - runs every VALID payload through the generated validate() and asserts it
 *     is ACCEPTED, hydrates it through from(), and (when a roundTrip key is
 *     given) asserts from()->toArray() preserves the value with no data loss or
 *     retyping;
 *   - runs every INVALID payload through validate() and asserts it is REJECTED.
 *
 * A valid payload that is rejected, or an invalid payload that is accepted, is a
 * fidelity mismatch. Mismatches are partitioned against a tracked known-gap list
 * (the ratchet): a listed gap is tolerated, an unlisted one fails the suite, and
 * a tracked gap that no longer reproduces also fails so the list stays honest.
 */
final class GoldenFidelityCase
{
    /**
     * @param  string  $construct  the construct family label for the report
     * @param  string  $class  generated class short-name in the conformance spec (e.g. ScalarsData)
     * @param  list<array{label: string, payload: array<string, mixed>, roundTrip?: array<string, mixed>}>  $valid
     * @param  list<array{label: string, payload: array<string, mixed>, violates: string}>  $invalid
     */
    public function __construct(
        public readonly string $construct,
        public readonly string $class,
        public readonly array $valid,
        public readonly array $invalid,
    ) {}
}

/**
 * The runtime-fidelity case table over the conformance-3.1 (and 3.0-forms)
 * golden contract. One entry per runtime-validatable named schema, with
 * boundary-style valid and invalid payloads plus round-trip expectations.
 *
 * Intentionally adversarial: several entries probe constructs the package once
 * documented as weak (undiscriminated object unions, mixed-type enums with a
 * boolean member) so the harness either confirms the documented gap or finds it
 * is worse than documented. additionalProperties:false is no longer a gap: it is
 * enforced by default, so its invalid payload is now expected to be rejected.
 */
final class GoldenFidelityCatalog
{
    /**
     * @return list<GoldenFidelityCase>
     */
    public static function cases(): array
    {
        return [
            // --- Scalars: type fidelity + round-trip without retyping --------
            new GoldenFidelityCase(
                'Scalars', 'ScalarsData',
                [
                    [
                        'label' => 'all scalar types preserve their PHP type',
                        'payload' => ['aString' => 's', 'anInt32' => 7, 'anInt64' => 42, 'aFloat' => 1.5, 'aDouble' => 2.5, 'aBoolean' => true],
                        'roundTrip' => ['aString' => 's', 'anInt32' => 7, 'anInt64' => 42, 'aFloat' => 1.5, 'aDouble' => 2.5, 'aBoolean' => true],
                    ],
                    [
                        'label' => 'a large int64 survives without precision loss',
                        'payload' => ['anInt64' => 9007199254740993],
                        'roundTrip' => ['anInt64' => 9007199254740993],
                    ],
                ],
                [
                    ['label' => 'a float on an integer field is rejected', 'payload' => ['anInt32' => 7.5], 'violates' => 'type:integer'],
                    ['label' => 'a string on an integer field is rejected', 'payload' => ['anInt32' => 'abc'], 'violates' => 'type:integer'],
                    ['label' => 'a string on a boolean field is rejected', 'payload' => ['aBoolean' => 'notbool'], 'violates' => 'type:boolean'],
                ],
            ),

            // --- String formats: enforced formats + date_format --------------
            new GoldenFidelityCase(
                'StringFormats', 'StringFormatsData',
                [
                    [
                        'label' => 'every enforced format with a valid value, value preserved',
                        'payload' => [
                            'aDate' => '2024-01-15',
                            'aDateTime' => '2024-01-15T10:30:00Z',
                            'aTime' => '14:30:00Z',
                            'aDuration' => 'P3Y6M4DT12H30M5S',
                            'anEmail' => 'a@b.com',
                            'aUuid' => '550e8400-e29b-41d4-a716-446655440000',
                            'aUri' => 'https://example.com/x',
                            'aHostname' => 'api.example.com',
                            'anIpv4' => '192.168.0.1',
                            'anIpv6' => '::1',
                        ],
                        'roundTrip' => [
                            'aDate' => '2024-01-15',
                            'aDateTime' => '2024-01-15T10:30:00Z',
                            'aTime' => '14:30:00Z',
                            'aDuration' => 'P3Y6M4DT12H30M5S',
                            'anEmail' => 'a@b.com',
                            'aUuid' => '550e8400-e29b-41d4-a716-446655440000',
                            'aUri' => 'https://example.com/x',
                            'aHostname' => 'api.example.com',
                            'anIpv4' => '192.168.0.1',
                            'anIpv6' => '::1',
                        ],
                    ],
                    // The other valid RFC3339 time spellings (#49): a numeric
                    // offset, fractional seconds, and a bare local time.
                    ['label' => 'a time with a numeric offset', 'payload' => ['aTime' => '14:30:00+02:00']],
                    ['label' => 'a time with fractional seconds', 'payload' => ['aTime' => '14:30:00.123Z']],
                    ['label' => 'a bare local time', 'payload' => ['aTime' => '14:30:00']],
                    // Other valid ISO 8601 durations (#49).
                    ['label' => 'a time-only duration', 'payload' => ['aDuration' => 'PT1H']],
                    ['label' => 'a single-day duration', 'payload' => ['aDuration' => 'P1D']],
                ],
                [
                    ['label' => 'a date-time on a date-only field is rejected', 'payload' => ['aDate' => '2024-01-15T10:30:00Z'], 'violates' => 'format:date'],
                    ['label' => 'an impossible calendar date is rejected', 'payload' => ['aDate' => '2024-13-99'], 'violates' => 'format:date'],
                    ['label' => 'a bare date on a date-time field is rejected', 'payload' => ['aDateTime' => '2024-01-15'], 'violates' => 'format:date-time'],
                    ['label' => 'free text on a date-time field is rejected', 'payload' => ['aDateTime' => 'tomorrow'], 'violates' => 'format:date-time'],
                    // format: time (#49): out-of-range, a full date-time, and free text.
                    ['label' => 'an out-of-range time is rejected', 'payload' => ['aTime' => '25:00:00'], 'violates' => 'format:time'],
                    ['label' => 'a full date-time on a time-only field is rejected', 'payload' => ['aTime' => '2024-01-15T14:30:00Z'], 'violates' => 'format:time'],
                    ['label' => 'free text on a time field is rejected', 'payload' => ['aTime' => 'noon'], 'violates' => 'format:time'],
                    // format: duration (#49): a bare P and free text.
                    ['label' => 'a bare P with no components is rejected', 'payload' => ['aDuration' => 'P'], 'violates' => 'format:duration'],
                    ['label' => 'free text on a duration field is rejected', 'payload' => ['aDuration' => 'noon'], 'violates' => 'format:duration'],
                    ['label' => 'malformed email is rejected', 'payload' => ['anEmail' => 'nope'], 'violates' => 'format:email'],
                    ['label' => 'malformed uuid is rejected', 'payload' => ['aUuid' => 'not-a-uuid'], 'violates' => 'format:uuid'],
                    ['label' => 'a uri with spaces is rejected', 'payload' => ['aUri' => 'not a uri'], 'violates' => 'format:uri'],
                    ['label' => 'a hostname with illegal characters is rejected', 'payload' => ['aHostname' => 'bad host!'], 'violates' => 'format:hostname'],
                    ['label' => 'an out-of-range ipv4 octet is rejected', 'payload' => ['anIpv4' => '999.1.1.1'], 'violates' => 'format:ipv4'],
                ],
            ),

            // --- String constraints: length + pattern ------------------------
            new GoldenFidelityCase(
                'StringConstraints', 'StringConstraintsData',
                [
                    [
                        'label' => 'bounded at lower edge and a matching pattern, preserved',
                        'payload' => ['bounded' => 'abc', 'patterned' => '#A1B2C3~tag'],
                        'roundTrip' => ['bounded' => 'abc', 'patterned' => '#A1B2C3~tag'],
                    ],
                    ['label' => 'bounded at upper edge', 'payload' => ['bounded' => 'abcdefghijkl']],
                ],
                [
                    ['label' => 'one char short of minLength is rejected', 'payload' => ['bounded' => 'ab'], 'violates' => 'minLength'],
                    ['label' => 'one char over maxLength is rejected', 'payload' => ['bounded' => 'abcdefghijklm'], 'violates' => 'maxLength'],
                    ['label' => 'a non-matching pattern is rejected', 'payload' => ['patterned' => 'nope'], 'violates' => 'pattern'],
                ],
            ),

            // --- Numeric constraints: inclusive + 3.1 exclusive + multipleOf -
            new GoldenFidelityCase(
                'NumericConstraints', 'NumericConstraintsData',
                [
                    [
                        'label' => 'inclusive at both bounds, exclusive inside, multiple, preserved',
                        'payload' => ['inclusive' => 100, 'exclusive' => 0.5, 'stepped' => 10],
                        'roundTrip' => ['inclusive' => 100, 'exclusive' => 0.5, 'stepped' => 10],
                    ],
                    ['label' => 'inclusive at the floor', 'payload' => ['inclusive' => 0]],
                    ['label' => 'zero is a multiple', 'payload' => ['stepped' => 0]],
                ],
                [
                    ['label' => 'below the inclusive minimum is rejected', 'payload' => ['inclusive' => -1], 'violates' => 'minimum'],
                    ['label' => 'above the inclusive maximum is rejected', 'payload' => ['inclusive' => 101], 'violates' => 'maximum'],
                    ['label' => 'equal to an exclusive minimum is rejected', 'payload' => ['exclusive' => 0], 'violates' => 'exclusiveMinimum'],
                    ['label' => 'equal to an exclusive maximum is rejected', 'payload' => ['exclusive' => 1], 'violates' => 'exclusiveMaximum'],
                    ['label' => 'a non-multiple is rejected', 'payload' => ['stepped' => 7], 'violates' => 'multipleOf'],
                ],
            ),

            // --- Enums: backed-enum membership at a use site -----------------
            // A top-level backed enum (StringEnum) is validated where it is
            // referenced. The conformance spec references StringEnum via
            // GadgetInput.kind, so membership + required are exercised there.
            new GoldenFidelityCase(
                'StringEnum (via GadgetInput.kind)',
                'GadgetInputData',
                [
                    [
                        'label' => 'a listed string-enum member hydrates the backed enum',
                        'payload' => ['kind' => 'red'],
                    ],
                    ['label' => 'another listed member', 'payload' => ['kind' => 'blue']],
                ],
                [
                    ['label' => 'an unlisted enum value is rejected', 'payload' => ['kind' => 'purple'], 'violates' => 'enum'],
                    ['label' => 'wrong case is rejected', 'payload' => ['kind' => 'RED'], 'violates' => 'enum'],
                    ['label' => 'a missing required enum is rejected', 'payload' => [], 'violates' => 'required'],
                ],
            ),

            // --- Float enum: Data class carrying Rule::in --------------------
            new GoldenFidelityCase(
                'FloatEnum', 'FloatEnumData',
                [
                    ['label' => 'a listed float value, preserved', 'payload' => ['value' => 1.5], 'roundTrip' => ['value' => 1.5]],
                    ['label' => 'the boundary float value', 'payload' => ['value' => 0.5]],
                ],
                [
                    ['label' => 'a float outside the enum is rejected', 'payload' => ['value' => 3.5], 'violates' => 'enum'],
                ],
            ),

            // --- Mixed-type enum: Rule::in over heterogeneous members --------
            new GoldenFidelityCase(
                'MixedTypeEnum', 'MixedTypeEnumData',
                [
                    ['label' => 'a string member', 'payload' => ['value' => 'one'], 'roundTrip' => ['value' => 'one']],
                    ['label' => 'an integer member', 'payload' => ['value' => 2]],
                    ['label' => 'a float member', 'payload' => ['value' => 3.5]],
                    // `true` is a spec-valid enum member: it now survives into
                    // Rule::in (the #bool-enum fix), so this valid payload is
                    // accepted rather than false-rejected.
                    ['label' => 'a boolean enum member must be accepted (true)', 'payload' => ['value' => true]],
                ],
                [
                    ['label' => 'a value outside the enum is rejected', 'payload' => ['value' => 99], 'violates' => 'enum'],
                ],
            ),

            // --- Object with required + types --------------------------------
            new GoldenFidelityCase(
                'ObjectWithRequired', 'ObjectWithRequiredData',
                [
                    [
                        'label' => 'both required present plus optional note, all preserved',
                        'payload' => ['id' => 5, 'name' => 'w', 'note' => 'n'],
                        'roundTrip' => ['id' => 5, 'name' => 'w', 'note' => 'n'],
                    ],
                    ['label' => 'optional note absent', 'payload' => ['id' => 1, 'name' => 'w']],
                ],
                [
                    ['label' => 'a missing required id is rejected', 'payload' => ['name' => 'w'], 'violates' => 'required'],
                    ['label' => 'a missing required name is rejected', 'payload' => ['id' => 1], 'violates' => 'required'],
                    ['label' => 'a wrong-typed id is rejected', 'payload' => ['id' => 'notint', 'name' => 'w'], 'violates' => 'type:integer'],
                ],
            ),

            // --- Widget ($ref target) ----------------------------------------
            new GoldenFidelityCase(
                'Widget', 'WidgetData',
                [
                    [
                        'label' => 'id + name + tags array, all preserved with element types',
                        'payload' => ['id' => 9, 'name' => 'w', 'tags' => ['a', 'b']],
                        'roundTrip' => ['id' => 9, 'name' => 'w', 'tags' => ['a', 'b']],
                    ],
                ],
                [
                    ['label' => 'a missing required id is rejected', 'payload' => ['name' => 'w'], 'violates' => 'required'],
                ],
            ),

            // --- ErrorObject (default-response object) ------------------------
            new GoldenFidelityCase(
                'ErrorObject', 'ErrorObjectData',
                [
                    ['label' => 'code + message preserved', 'payload' => ['code' => 1, 'message' => 'boom'], 'roundTrip' => ['code' => 1, 'message' => 'boom']],
                ],
                [
                    ['label' => 'a missing required message is rejected', 'payload' => ['code' => 1], 'violates' => 'required'],
                ],
            ),

            // --- allOf merge: required across members + sibling --------------
            new GoldenFidelityCase(
                'AllOfWithSiblings', 'AllOfWithSiblingsData',
                [
                    [
                        'label' => 'merged required + own sibling present, preserved',
                        'payload' => ['id' => 1, 'name' => 'n', 'sibling' => 's'],
                        'roundTrip' => ['id' => 1, 'name' => 'n', 'note' => null, 'sibling' => 's'],
                    ],
                ],
                [
                    ['label' => 'a missing merged-required id is rejected', 'payload' => ['name' => 'n', 'sibling' => 's'], 'violates' => 'allOf.required'],
                    ['label' => 'a missing own-sibling is rejected', 'payload' => ['id' => 1, 'name' => 'n'], 'violates' => 'required'],
                ],
            ),

            new GoldenFidelityCase(
                'AllOfNested', 'AllOfNestedData',
                [
                    ['label' => 'nested-allOf merged members present', 'payload' => ['id' => 1, 'name' => 'n', 'inner' => 'i', 'outer' => 'o']],
                ],
                [
                    ['label' => 'a missing deeply-merged required is rejected', 'payload' => ['name' => 'n'], 'violates' => 'allOf.required'],
                ],
            ),

            // --- Defaults: seeding + constraints -----------------------------
            new GoldenFidelityCase(
                'Defaults', 'DefaultsData',
                [
                    [
                        'label' => 'an empty payload seeds every default',
                        'payload' => [],
                        'roundTrip' => ['optionalWithDefault' => 'fallback', 'withDefaultAndRequired' => 0, 'enumWithDefault' => 'b', 'nullableWithDefault' => null],
                    ],
                    [
                        'label' => 'overriding the defaults preserves the override',
                        'payload' => ['optionalWithDefault' => 'x', 'withDefaultAndRequired' => 9, 'enumWithDefault' => 'c'],
                        'roundTrip' => ['optionalWithDefault' => 'x', 'withDefaultAndRequired' => 9, 'enumWithDefault' => 'c', 'nullableWithDefault' => null],
                    ],
                ],
                [
                    ['label' => 'a value outside the default-backed enum is rejected', 'payload' => ['enumWithDefault' => 'zzz'], 'violates' => 'enum'],
                ],
            ),

            // --- Nullability: type-array nullable + multi-type unions --------
            new GoldenFidelityCase(
                'Nullability', 'NullabilityData',
                [
                    [
                        'label' => 'present-null stays null, an int union member keeps its int type',
                        'payload' => ['nullableString' => null, 'stringOrInteger' => 42, 'stringIntOrNull' => null],
                        'roundTrip' => ['nullableString' => null, 'stringOrInteger' => 42, 'stringIntOrNull' => null],
                    ],
                    [
                        'label' => 'a string union member keeps its string type',
                        'payload' => ['stringOrInteger' => 'hi', 'stringIntOrNull' => 7],
                        'roundTrip' => ['nullableString' => null, 'stringOrInteger' => 'hi', 'stringIntOrNull' => 7],
                    ],
                ],
                [
                    // A union is presence-only by design (a single type rule would
                    // false-reject the other member), so there is no targeted
                    // reject here; type fidelity is asserted via round-trip above.
                ],
            ),

            // --- NullableMixedOneOf: required mixed (#8) ----------------------
            new GoldenFidelityCase(
                'NullableMixedOneOf', 'NullableMixedOneOfData',
                [
                    ['label' => 'a present scalar satisfies required mixed', 'payload' => ['value' => 'x'], 'roundTrip' => ['value' => 'x']],
                    // The oneOf includes type:"null", so a present null is
                    // spec-valid. The required+nullable mixed fallback now emits
                    // `present` + `nullable` (the #8 fix), so a present null is
                    // accepted while a missing key is still rejected.
                    ['label' => 'a present null satisfies the nullable oneOf member', 'payload' => ['value' => null]],
                ],
                [
                    ['label' => 'a missing required value is rejected', 'payload' => [], 'violates' => 'required'],
                ],
            ),

            // --- readOnly/writeOnly split ------------------------------------
            new GoldenFidelityCase(
                'ReadWriteOnly (read view)', 'ReadWriteOnlyData',
                [
                    [
                        'label' => 'the read view keeps readOnly id, never the writeOnly password',
                        'payload' => ['id' => 5],
                        'roundTrip' => ['id' => 5, 'nested' => null, 'tokens' => null],
                    ],
                ],
                [
                    ['label' => 'a missing required id is rejected', 'payload' => [], 'violates' => 'required'],
                ],
            ),
            new GoldenFidelityCase(
                'ReadWriteOnly (writable view)', 'ReadWriteOnlyWritableData',
                [
                    [
                        'label' => 'the writable view keeps the writeOnly password',
                        'payload' => ['password' => 'secret'],
                        'roundTrip' => ['password' => 'secret', 'nested' => null, 'tokens' => null],
                    ],
                ],
                [],
            ),

            // --- Discriminated union member, standalone reuse ----------------
            new GoldenFidelityCase(
                'OneOfDiscriminated (variant GadgetAlpha)', 'GadgetAlphaData',
                [
                    ['label' => 'a valid alpha variant', 'payload' => ['kind' => 'alpha', 'alphaField' => 'x']],
                ],
                [
                    ['label' => 'a missing variant-required field is rejected', 'payload' => ['kind' => 'alpha'], 'violates' => 'variant.required'],
                    // The variant now pins its own discriminator const (kind:
                    // alpha) with a Rule::in, so validating the variant standalone
                    // rejects a mismatched discriminator value (the #disc-const
                    // fix). Morph routing via the base is unaffected.
                    ['label' => 'a variant whose discriminator const does not match its own value must be rejected', 'payload' => ['kind' => 'beta', 'alphaField' => 'x'], 'violates' => 'discriminator.const'],
                ],
            ),

            // --- Inline-union discriminated form (#38) -----------------------
            // The abstract morphable base over an INLINE oneOf + discriminator.
            // A valid payload routes to the synthesized variant and validates its
            // own required fields; a wrong/unmapped/missing discriminator, or a
            // missing variant-required field, is rejected. Fully enforced.
            new GoldenFidelityCase(
                'InlineDiscriminatedUnion (inline form #38)', 'InlineDiscriminatedUnionData',
                [
                    ['label' => 'circle discriminator with a valid circle shape', 'payload' => ['shapeKind' => 'circle', 'radius' => 1.5]],
                    ['label' => 'square discriminator with a valid square shape', 'payload' => ['shapeKind' => 'square', 'side' => 2.0]],
                ],
                [
                    ['label' => 'circle discriminator missing the circle-required radius', 'payload' => ['shapeKind' => 'circle'], 'violates' => 'inline.variant.required'],
                    ['label' => 'an unmapped inline discriminator value is rejected', 'payload' => ['shapeKind' => 'triangle'], 'violates' => 'discriminator.unmapped'],
                    ['label' => 'a missing inline discriminator is rejected', 'payload' => ['radius' => 1.0], 'violates' => 'discriminator.missing'],
                    // A circle payload carrying the square-only field but the circle
                    // discriminator stays routed to circle, so its required radius
                    // is still enforced (the wrong-shape field does not satisfy it).
                    ['label' => 'circle discriminator with only the square field is rejected', 'payload' => ['shapeKind' => 'circle', 'side' => 2.0], 'violates' => 'inline.variant.required'],
                ],
            ),

            // --- allOf-inheritance discriminated form (#38) ------------------
            // The abstract morphable base declared directly on an object, with
            // variants composed via allOf. A valid payload routes to the right
            // variant and its required allOf field is enforced.
            new GoldenFidelityCase(
                'Vehicle (allOf-inheritance form #38)', 'VehicleData',
                [
                    ['label' => 'car discriminator with a valid car shape', 'payload' => ['vehicleType' => 'car', 'doors' => 4]],
                    ['label' => 'truck discriminator with a valid truck shape', 'payload' => ['vehicleType' => 'truck', 'payloadKg' => 1200]],
                    ['label' => 'a base-shared optional field is carried on the variant', 'payload' => ['vehicleType' => 'car', 'doors' => 2, 'wheels' => 4]],
                ],
                [
                    ['label' => 'car discriminator missing the car-required doors', 'payload' => ['vehicleType' => 'car'], 'violates' => 'allof.variant.required'],
                    ['label' => 'an unmapped allOf discriminator value is rejected', 'payload' => ['vehicleType' => 'boat'], 'violates' => 'discriminator.unmapped'],
                    ['label' => 'a missing allOf discriminator is rejected', 'payload' => ['doors' => 4], 'violates' => 'discriminator.missing'],
                ],
            ),

            // --- Recursion: self-referential schema --------------------------
            new GoldenFidelityCase(
                'TreeNode (recursion)', 'TreeNodeData',
                [
                    ['label' => 'a node with nested children hydrates recursively', 'payload' => ['value' => 'root', 'children' => [['value' => 'child']]]],
                ],
                [
                    ['label' => 'a non-array children is rejected', 'payload' => ['children' => 'notarray'], 'violates' => 'type:array'],
                ],
            ),

            // --- Deep ref chain ----------------------------------------------
            new GoldenFidelityCase(
                'ChainA (deep ref chain)', 'ChainAData',
                [
                    ['label' => 'a nested chain A -> B -> C hydrates', 'payload' => ['next' => ['next' => ['leaf' => 'end']]]],
                    ['label' => 'an absent next link is allowed', 'payload' => []],
                ],
                [],
            ),

            // --- Exerciser: maps, arrays, tuples, unions at the use site -----
            new GoldenFidelityCase(
                'Exerciser (maps/arrays/aliases)', 'ExerciserData',
                [
                    [
                        'label' => 'scalar array within bounds + distinct, preserved',
                        'payload' => ['scalarArray' => ['a', 'b']],
                        'roundTrip' => ['scalarArray' => ['a', 'b']],
                    ],
                    [
                        'label' => 'a scalar map of string values, preserved',
                        'payload' => ['scalarMap' => ['k1' => 'v1', 'k2' => 'v2']],
                        'roundTrip' => ['scalarMap' => ['k1' => 'v1', 'k2' => 'v2']],
                    ],
                    [
                        'label' => 'a scalar-alias min:1 string at its use site',
                        'payload' => ['scalarAlias' => 'x'],
                        'roundTrip' => ['scalarAlias' => 'x'],
                    ],
                    [
                        'label' => 'a scalar union member keeps its type (int)',
                        'payload' => ['scalarUnion' => 7],
                        'roundTrip' => ['scalarUnion' => 7],
                    ],
                ],
                [
                    ['label' => 'a scalar array below minItems is rejected', 'payload' => ['scalarArray' => []], 'violates' => 'minItems'],
                    ['label' => 'a scalar array above maxItems is rejected', 'payload' => ['scalarArray' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k']], 'violates' => 'maxItems'],
                    ['label' => 'duplicate items in a uniqueItems array are rejected', 'payload' => ['scalarArray' => ['a', 'a']], 'violates' => 'uniqueItems'],
                    // Note: an empty string cannot be a reliable minLength reject
                    // probe under an optional (`sometimes`) rule, because Laravel
                    // skips size rules for a blank optional value, so `min:1` never
                    // fires on `''`. That is validator semantics, not a generator
                    // gap, so it is deliberately not asserted here.
                    // KNOWN-GAP probe (#31): an undiscriminated object union accepts
                    // any object, so a bogus shape is wrongly accepted.
                    ['label' => 'an object matching no union variant must be rejected (objectUnion)', 'payload' => ['objectUnion' => ['nope' => 1]], 'violates' => 'oneOf.no-match'],
                ],
            ),

            // --- additionalProperties: false (closed object) -----------------
            new GoldenFidelityCase(
                'AdditionalPropsFalse', 'AdditionalPropsFalseData',
                [
                    ['label' => 'only the declared property, preserved', 'payload' => ['known' => 'x'], 'roundTrip' => ['known' => 'x']],
                ],
                [
                    // Enforced by default (#30): closed-object enforcement is on, so
                    // an extra undeclared key is rejected through the real validator.
                    ['label' => 'an undeclared extra property must be rejected', 'payload' => ['known' => 'x', 'extra' => 'y'], 'violates' => 'additionalProperties:false'],
                ],
            ),
        ];
    }

    /**
     * Cases over the 3.0-forms companion document (nullable:true spellings, the
     * boolean exclusive-bound form). Keyed by the generated class short-name in
     * conformance-3.0-forms.yaml.
     *
     * @return list<GoldenFidelityCase>
     */
    public static function cases30(): array
    {
        return [
            new GoldenFidelityCase(
                '3.0 BooleanExclusiveBounds', 'BooleanExclusiveBoundsData',
                [
                    [
                        'label' => 'inside both exclusive bounds, preserved',
                        'payload' => ['exclusiveLow' => 1, 'exclusiveHigh' => 0.5],
                        'roundTrip' => ['exclusiveLow' => 1, 'exclusiveHigh' => 0.5],
                    ],
                ],
                [
                    ['label' => 'equal to the boolean exclusive minimum is rejected', 'payload' => ['exclusiveLow' => 0], 'violates' => 'exclusiveMinimum'],
                    ['label' => 'equal to the boolean exclusive maximum is rejected', 'payload' => ['exclusiveHigh' => 1], 'violates' => 'exclusiveMaximum'],
                ],
            ),
            new GoldenFidelityCase(
                '3.0 NullableObject', 'NullableObjectData',
                [
                    ['label' => 'a nullable object with members, preserved', 'payload' => ['id' => 1, 'label' => 'x'], 'roundTrip' => ['id' => 1, 'label' => 'x']],
                ],
                [
                    ['label' => 'a wrong-typed member is rejected', 'payload' => ['id' => 'notint'], 'violates' => 'type:integer'],
                ],
            ),
        ];
    }

    /**
     * Documented limitations the fidelity harness is expected to surface, each
     * tracked by a GitHub issue (or a clear reason). A mismatch matching one of
     * these (same construct + payload + direction) is reported but does NOT fail
     * the suite. A tracked gap that no longer reproduces fails the suite so its
     * entry is removed once the limitation is fixed. Any mismatch not listed
     * here is new drift and always fails. This is the ratchet that keeps the
     * harness green while staying honest about what is unenforced.
     *
     * @return list<array{construct: string, class: string, label: string, expected: string, actual: string, issue: string, reason: string}>
     */
    public static function knownGaps(): array
    {
        return [
            [
                'construct' => 'OneOfNoDiscriminator',
                'class' => 'ExerciserData',
                'label' => 'an object matching no union variant must be rejected (objectUnion)',
                'expected' => 'reject',
                'actual' => 'accept',
                'issue' => '#31',
                'reason' => 'An undiscriminated object union is presence-only: any object is accepted, no variant shape is enforced. The interim fix traded variant enforcement for not false-rejecting a valid non-first variant.',
            ],
        ];
    }

    public static function gapKey(string $class, string $label, string $expected, string $actual): string
    {
        return $class.'|'.$label.'|'.$expected.'|'.$actual;
    }
}
