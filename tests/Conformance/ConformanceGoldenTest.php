<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Golden conformance test for the openapi-laravel generator.
 *
 * The two conformance fixtures (tests/Fixtures/conformance/) are kitchen-sink
 * documents whose only job is to exercise every construct the generator must
 * handle. Their manifest (the conformance README) maps each schema/operation to
 * its construct family and issue number. This test turns that fixture into a
 * permanent guard over the whole generator surface, in three layers:
 *
 *   1. COMPILE: every generated file passes `php -l` (the real compiler, not
 *      just token_get_all), with no per-file cap and no exemptions. This is the
 *      comprehensive compile gate over the full construct surface.
 *   2. PER-CONSTRUCT: each major construct family from the manifest has a
 *      concrete assertion against real generated output (exclusive bounds,
 *      float enums, multi-type unions, date/date-time rules, multipleOf,
 *      uniqueItems, defaults, non-object aliases, readOnly/writeOnly split,
 *      additionalProperties maps, scalar/object unions, enums, const, ...).
 *   3. DETERMINISM: generating twice yields byte-identical output.
 *
 * There is deliberately NO committed full-output snapshot file: it would churn
 * on every cosmetic change. The compile gate plus the per-construct assertions
 * plus the determinism check ARE the guard.
 *
 * Many array/map/alias constructs are top-level non-object components, which by
 * issue #9 are inlined at their use site rather than emitted as their own class.
 * Their generated shape is therefore only observable where they are referenced,
 * so the fixture's `Exerciser` schema references one of each; the per-construct
 * assertions below read its `ExerciserData` output.
 */
const CONFORMANCE_31 = __DIR__.'/../Fixtures/conformance/conformance-3.1.yaml';

const CONFORMANCE_30 = __DIR__.'/../Fixtures/conformance/conformance-3.0-forms.yaml';

/**
 * Generate the 3.1 conformance fixture once and memoize it for the suite.
 *
 * @return array<string, GeneratedFile>
 */
function conformance31(): array
{
    static $files = null;
    if ($files === null) {
        $document = (new SpecParser)->parseFile(CONFORMANCE_31);
        $files = (new ModelGenerator)->generate($document);
    }

    return $files;
}

/**
 * Generate the 3.0 companion fixture once and memoize it for the suite.
 *
 * @return array<string, GeneratedFile>
 */
function conformance30(): array
{
    static $files = null;
    if ($files === null) {
        $document = (new SpecParser)->parseFile(CONFORMANCE_30);
        $files = (new ModelGenerator)->generate($document);
    }

    return $files;
}

/**
 * Convenience: the rendered source of one generated class from the 3.1 fixture.
 */
function conformanceCode(string $class): string
{
    $files = conformance31();
    expect($files)->toHaveKey($class);

    return $files[$class]->code;
}

/**
 * The inlined runtime support classes (issue #40) for the 3.1 fixture, memoized.
 * The fixture exercises date-time, duration, hostname, multipleOf, and map
 * transformer constructs, so it drives the full inlined support set.
 *
 * @return array<string, GeneratedFile>
 */
function conformanceSupport(): array
{
    static $support = null;
    if ($support === null) {
        $document = (new SpecParser)->parseFile(CONFORMANCE_31);
        $generator = new ModelGenerator;
        $generator->generate($document);
        $support = $generator->supportFiles();
    }

    return $support;
}

// ===========================================================================
// LAYER 1: COMPILE GATE (full surface, no cap, no exemptions)
// ===========================================================================

it('compiles every generated file with php -l, both conformance documents', function (string $path) {
    $document = (new SpecParser)->parseFile($path);
    $generator = new ModelGenerator;
    $files = $generator->generate($document);
    // The inlined runtime support classes (issue #40) are owned output too, so
    // lint them alongside the Data classes: they must compile as real PHP in the
    // consumer namespace.
    $files = [...$files, ...$generator->supportFiles()];

    // The conformance fixtures are the comprehensive construct surface: lint
    // EVERY emitted file, uncapped. phpLintFailures runs the real `php -l`
    // compiler via the bounded pool defined in tests/Pest.php.
    $failures = phpLintFailures($files, basename($path), null);

    expect($failures)->toBe([], implode("\n", $failures));
})->with([
    'conformance-3.1' => [CONFORMANCE_31],
    'conformance-3.0-forms' => [CONFORMANCE_30],
]);

it('emits at least the full set of conformance classes (the fixture really exercised the generator)', function () {
    // A floor, not an exact list: this catches a regression that silently stops
    // emitting whole families of classes, without churning when one is added.
    $classes = array_keys(conformance31());

    expect(count($classes))->toBeGreaterThanOrEqual(40)
        ->and($classes)->toContain('ScalarsData')
        ->and($classes)->toContain('ExerciserData')
        ->and($classes)->toContain('StringEnum')
        ->and($classes)->toContain('ReadWriteOnlyWritableData');
});

// ===========================================================================
// LAYER 2: PER-CONSTRUCT ASSERTIONS
// Each major construct family from the manifest, on real generated output.
// ===========================================================================

// --- Scalars and numeric formats ------------------------------------------

it('maps every scalar type and numeric format to its PHP type (Scalars)', function () {
    $code = conformanceCode('ScalarsData');

    expect($code)->toContain('public readonly ?string $aString = null')
        ->and($code)->toContain('public readonly ?int $anInt32 = null')
        ->and($code)->toContain('public readonly ?int $anInt64 = null')
        ->and($code)->toContain('public readonly ?float $aFloat = null')
        ->and($code)->toContain('public readonly ?float $aDouble = null')
        ->and($code)->toContain('public readonly ?bool $aBoolean = null');
});

// --- String formats: date vs date-time, plus documented no-ops (#13, #18) --

it('emits date_format:Y-m-d for date and the Rfc3339 rule for date-time (StringFormats)', function () {
    $code = conformanceCode('StringFormatsData');

    expect($code)->toContain("'aDate' => ['sometimes', 'string', 'date_format:Y-m-d'],")
        ->and($code)->toContain("'aDateTime' => ['sometimes', 'string', new Rfc3339DateTimeRule],")
        // The rule is imported from the consumer's own Support namespace (issue
        // #40), never from the generator package, so the output is self-contained.
        ->and($code)->toContain('use App\Data\Support\Rfc3339DateTimeRule;')
        // A documented no-op format (password / custom) stays a plain string
        // with no extra rule, never a fatal or an invented rule.
        ->and($code)->toContain("'aPassword' => ['sometimes', 'string'],")
        ->and($code)->toContain("'aCustomFormat' => ['sometimes', 'string'],");
});

// --- Inlined runtime support classes (#40) ---------------------------------

it('inlines only the referenced support classes into the consumer Support namespace, self-contained (#40)', function () {
    $support = conformanceSupport();

    // Only the support classes the fixture actually references are emitted, and
    // the set is deterministic and sorted. The fixture exercises hostname,
    // duration, multipleOf, date-time, and time formats, and it declares a
    // closed object (additionalProperties: false). Closed-object enforcement is
    // on by default (#30), so NoUnknownPropertiesRule is part of the default set.
    expect(array_keys($support))->toBe([
        'HostnameRule',
        'Iso8601DurationRule',
        'MapObjectTransformer',
        'MultipleOfRule',
        'NoUnknownPropertiesRule',
        'Rfc3339DateTimeRule',
        'Rfc3339TimeRule',
    ]);

    foreach ($support as $shortName => $file) {
        expect($file->code)
            // Each inlined copy lives in the consumer's own Support namespace.
            ->toContain('namespace App\Data\Support;')
            // and carries NO reference to the generator's own Support namespace,
            // so generated output is fully self-contained (zero runtime coupling).
            ->not->toContain('CodeWithAgents\OpenApiLaravel\Support')
            ->and($file->code)->toContain('class '.$shortName);
    }
});

it('inlines NoUnknownPropertiesRule by default and drops it under --no-enforce-closed-objects (#40, #30)', function () {
    $document = (new SpecParser)->parseFile(CONFORMANCE_31);

    // By default, closed-object enforcement is on (#30), so any spec that
    // declares a closed object pulls the rule into the inlined set. The
    // conformance fixture exercises closed objects, so the rule appears by
    // default and is itself self-contained.
    $strict = new ModelGenerator;
    $strict->generate($document);
    $strictSupport = $strict->supportFiles();
    expect(array_keys($strictSupport))->toContain('NoUnknownPropertiesRule')
        ->and($strictSupport['NoUnknownPropertiesRule']->code)->toContain('namespace App\Data\Support;')
        ->and($strictSupport['NoUnknownPropertiesRule']->code)->not->toContain('CodeWithAgents\OpenApiLaravel\Support');

    // Opting out (--no-enforce-closed-objects, enforceClosedObjects: false) drops
    // the closed-object rule: it is never referenced, so it is not inlined.
    $lenient = new ModelGenerator(new GeneratorOptions(enforceClosedObjects: false));
    $lenient->generate($document);
    expect(array_keys($lenient->supportFiles()))->not->toContain('NoUnknownPropertiesRule');
});

it('emits the NoUnknownPropertiesRule in a closed-object Data class by default, and drops it on opt-out (#30)', function () {
    // Default run: the closed-object schema's rules() carries the rule keyed by
    // its non-colliding sentinel, with the declared wire names allow-listed, and
    // imports the rule from the consumer's own Support namespace (#40).
    $code = conformanceCode('AdditionalPropsFalseData');
    expect($code)->toContain('new NoUnknownPropertiesRule(')
        ->and($code)->toContain('use App\Data\Support\NoUnknownPropertiesRule;');

    // Opt-out run: the same schema emits no closed-object rule and no import.
    $document = (new SpecParser)->parseFile(CONFORMANCE_31);
    $lenient = (new ModelGenerator(new GeneratorOptions(enforceClosedObjects: false)))->generate($document);
    expect($lenient)->toHaveKey('AdditionalPropsFalseData');
    expect($lenient['AdditionalPropsFalseData']->code)
        ->not->toContain('NoUnknownPropertiesRule');
});

// --- Numeric constraints: exclusive bounds + multipleOf (#10, #14) ---------

it('emits gt/lt (not min/max) for 3.1 numeric exclusive bounds and a MultipleOfRule (NumericConstraints)', function () {
    $code = conformanceCode('NumericConstraintsData');

    expect($code)->toContain("'inclusive' => ['sometimes', 'integer', 'min:0', 'max:100'],")
        // Exclusive bounds become gt:/lt:, never min:/max:.
        ->and($code)->toContain("'exclusive' => ['sometimes', 'numeric', 'gt:0', 'lt:1'],")
        ->and($code)->not->toContain("'gt:0', 'min:0'")
        // multipleOf becomes the MultipleOfRule, imported.
        ->and($code)->toContain('new MultipleOfRule(5)')
        ->and($code)->toContain('use App\Data\Support\MultipleOfRule;');
});

it('emits gt/lt for the 3.0 boolean exclusive-bounds form (BooleanExclusiveBounds)', function () {
    $files = conformance30();
    expect($files)->toHaveKey('BooleanExclusiveBoundsData');

    $code = $files['BooleanExclusiveBoundsData']->code;

    expect($code)->toContain("'exclusiveLow' => ['sometimes', 'integer', 'gt:0'],")
        ->and($code)->toContain("'exclusiveHigh' => ['sometimes', 'numeric', 'lt:1'],")
        ->and($code)->not->toContain('min:0')
        ->and($code)->not->toContain('max:1');
});

// --- Multi-type arrays / nullability (#12) ---------------------------------

it('emits a union for a two-real-type array and keeps [T,null] a nullable scalar (Nullability)', function () {
    $code = conformanceCode('NullabilityData');

    // [string, "null"] is a nullable string, NOT a union.
    expect($code)->toContain('public readonly ?string $nullableString = null')
        // [string, integer] is a real two-type union with a variant docblock.
        ->and($code)->toContain('/** @var string|int */')
        ->and($code)->toContain('public readonly string|int|null $stringOrInteger = null')
        // [string, integer, "null"] is the same union, made nullable.
        ->and($code)->toContain('/** @var string|int|null */')
        ->and($code)->toContain('public readonly string|int|null $stringIntOrNull = null')
        // A union carries presence-only rules: no 'string' rule that rejects an int.
        ->and($code)->toContain("'stringOrInteger' => ['sometimes'],");
});

// --- Enums: string, integer, float, mixed, const (#11) ---------------------

it('emits a string-backed enum for a string enum (StringEnum)', function () {
    $code = conformanceCode('StringEnum');

    expect($code)->toContain('enum StringEnum: string')
        ->and($code)->toContain("case Red = 'red';")
        ->and($code)->toContain("case Blue = 'blue';");
});

it('emits an int-backed enum for an integer enum (IntegerEnum)', function () {
    $code = conformanceCode('IntegerEnum');

    expect($code)->toContain('enum IntegerEnum: int')
        ->and($code)->toContain('case Value1 = 1;')
        ->and($code)->toContain('case Value3 = 3;');
});

it('emits a value-carrying Data class with a float Rule::in for a float enum, not an empty class (FloatEnum)', function () {
    // A float enum cannot be a PHP backed enum: it must be a Data class that
    // carries the float constraint, never an empty class or a dropped value.
    $code = conformanceCode('FloatEnumData');

    expect($code)->toContain('public readonly float $value')
        ->and($code)->toContain('Rule::in([0.5, 1.5, 2.5])')
        ->and($code)->toContain('public function __construct');
});

it('keeps a mixed-type enum a mixed property with an in rule (MixedTypeEnum)', function () {
    $code = conformanceCode('MixedTypeEnumData');

    expect($code)->toContain('public readonly mixed $value')
        ->and($code)->toContain('Rule::in([');
});

it('drops the null member from an enum-containing-null and keeps a clean backed enum (EnumWithNull)', function () {
    $code = conformanceCode('EnumWithNull');

    // null is not an enum case: only the two real members survive (the enum
    // NAME legitimately contains "Null", so assert on the absence of a null case).
    expect($code)->toContain('enum EnumWithNull: string')
        ->and($code)->toContain("case Active = 'active';")
        ->and($code)->toContain("case Inactive = 'inactive';")
        ->and($code)->not->toContain('= null')
        ->and($code)->not->toContain("= 'null'");
});

// --- Objects, required, additionalProperties maps --------------------------

it('emits required vs optional properties for an object with required (ObjectWithRequired)', function () {
    $code = conformanceCode('ObjectWithRequiredData');

    expect($code)->toContain("'id' => ['required', 'integer'],")
        ->and($code)->toContain("'name' => ['required', 'string'],")
        ->and($code)->toContain("'note' => ['sometimes', 'string'],");
});

it('documents the uncaptured overflow for a mixed object with additionalProperties (MixedObject)', function () {
    $code = conformanceCode('MixedObjectData');

    expect($code)->toContain('public readonly ?int $id = null')
        ->and($code)->toContain('public readonly ?string $label = null')
        // The dynamic overflow is documented, never silently dropped.
        ->and($code)->toContain('additionalProperties')
        ->and($code)->toContain('not captured');
});

it('represents additionalProperties maps as typed array<string, T> with wildcard rules and the map transformer (Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    // map of string -> array<string, string> + wildcard string rule.
    expect($code)->toContain('/** @var array<string, string> */')
        ->and($code)->toContain("'scalarMap.*' => ['string'],")
        // map of $ref -> array<string, WidgetData>.
        ->and($code)->toContain('/** @var array<string, WidgetData> */')
        // every map carries the {} transformer so an empty map serializes as {} not [].
        ->and($code)->toContain('#[WithTransformer(MapObjectTransformer::class)]')
        ->and($code)->toContain('use Spatie\LaravelData\Attributes\WithTransformer;')
        // The transformer is imported from the consumer's own Support namespace
        // (issue #40), not the generator package.
        ->and($code)->toContain('use App\Data\Support\MapObjectTransformer;');
});

// --- Arrays: scalar items + uniqueItems, array of $ref, scalar alias (#14, #9) -

it('emits a uniqueItems distinct rule and array bounds for a scalar array (Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    expect($code)->toContain('/** @var array<int, string> */')
        ->and($code)->toContain("'scalarArray' => ['sometimes', 'array', 'max:10', 'min:1'],")
        // uniqueItems contributes a per-item distinct rule.
        ->and($code)->toContain("'scalarArray.*' => ['string', 'distinct'],");
});

it('emits a DataCollection for an array of $ref objects (Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    expect($code)->toContain('/** @var array<int, WidgetData> */')
        ->and($code)->toContain('#[DataCollectionOf(WidgetData::class)]')
        ->and($code)->toContain('public readonly ?array $refArray = null');
});

it('emits a plain typed array, never DataCollectionOf(A|B::class), for an array of a union (#24)', function () {
    $code = conformanceCode('ExerciserData');

    // A union element is a plain `array` with an `array<int, A|B>` docblock and
    // no collection attribute. The invalid `#[DataCollectionOf(A|B::class)]`
    // (which php -l accepts via operator precedence but is wrong at runtime)
    // must never be emitted.
    expect($code)->toContain('/** @var array<int, GadgetAlphaData|GadgetBetaData> */')
        ->and($code)->toContain('public readonly ?array $unionArray = null')
        ->and($code)->not->toContain('GadgetAlphaData|GadgetBetaData::class')
        ->and($code)->not->toContain('DataCollectionOf(GadgetAlphaData');
});

it('emits a plain typed array with NO DataCollectionOf for an array of a backed enum (#62, Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    // A backed-enum element is a native PHP enum, not a Data class. The property
    // is a plain typed array carrying an `array<int, StringEnum>` docblock with
    // NO `#[DataCollectionOf(StringEnum::class)]` attribute: DataCollectionOf
    // targets class-string<BaseData>, so the attribute fails PHPStan max and
    // throws at runtime (CannotFindDataClass). spatie hydrates the backed enum
    // from the typed array via the docblock alone.
    expect($code)->toContain('/** @var array<int, StringEnum> */')
        ->and($code)->toContain('public readonly ?array $enumArray = null')
        ->and($code)->not->toContain('DataCollectionOf(StringEnum::class)');
});

it('emits a fully-generic nested-array docblock, never a bare inner array, for an array of array (#62, Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    // A nested array (array of array of integer) must carry a fully recursive
    // generic so the inner element type is specified too: `array<int, array<int,
    // int>>`, never the PHPStan-rejected `array<int, array>` (missingType.iterableValue).
    expect($code)->toContain('/** @var array<int, array<int, int>> */')
        ->and($code)->toContain('public readonly ?array $nestedArray = null')
        ->and($code)->not->toContain('@var array<int, array> ');
});

// --- Non-object component aliases (#9): NO empty Data class -----------------

it('inlines non-object alias components instead of emitting empty Data classes (#9)', function () {
    $classes = array_keys(conformance31());

    // None of the scalar/array/oneOf/map top-level aliases becomes a class.
    foreach ([
        'ScalarAliasData', 'ArrayAliasData', 'OneOfAliasData', 'OneOfScalarsData',
        'AdditionalPropsScalarData', 'AdditionalPropsRefData', 'MapOfObjectsData',
        'ArrayScalarData', 'ArrayOfRefData', 'ArrayOfUnionData', 'ArrayOfArrayData',
        'TupleSchemaData', 'OneOfNoDiscriminatorData',
    ] as $absent) {
        expect($classes)->not->toContain($absent);
    }
});

it('inlines a scalar alias to its scalar type with the alias constraint at the use site (Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    // ScalarAlias (string, minLength: 1) inlines to a string property carrying min:1.
    expect($code)->toContain('public readonly ?string $scalarAlias = null')
        ->and($code)->toContain("'scalarAlias' => ['sometimes', 'string', 'min:1'],")
        ->and($code)->not->toContain('ScalarAliasData');
});

it('inlines an array alias to its array type at the use site (Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    expect($code)->toContain('public readonly ?array $arrayAlias = null')
        ->and($code)->toContain("'arrayAlias.*' => ['string'],")
        ->and($code)->not->toContain('ArrayAliasData');
});

// --- Unions: oneOf of scalars, oneOf of objects, ?mixed (#8) ---------------

it('inlines a oneOf-of-scalars alias to a native scalar union (Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    // OneOfAlias is oneOf [string, integer]; OneOfScalars is oneOf [string, integer, boolean].
    expect($code)->toContain('/** @var string|int */')
        ->and($code)->toContain('public readonly string|int|null $oneOfAlias = null')
        ->and($code)->toContain('/** @var string|int|bool */')
        ->and($code)->toContain('public readonly string|int|bool|null $scalarUnion = null')
        // Unions get presence-only rules.
        ->and($code)->toContain("'oneOfAlias' => ['sometimes'],");
});

it('types an undiscriminated oneOf of $ref objects as mixed, keeping the variant docblock (issue #31, Exerciser)', function () {
    $code = conformanceCode('ExerciserData');

    // A native `GadgetAlphaData|GadgetBetaData` property type makes laravel-data
    // infer nested rules from the union and validate every payload against the
    // first variant, false-rejecting a valid non-first variant (issue #31). The
    // interim fix types the property `mixed` (presence-only, accept any object)
    // while keeping the variant union in the `@var` docblock for IDE/PHPStan.
    expect($code)->toContain('/** @var GadgetAlphaData|GadgetBetaData */')
        ->and($code)->toContain('public readonly mixed $objectUnion = null')
        ->and($code)->not->toContain('GadgetAlphaData|GadgetBetaData $objectUnion');
});

it('resolves a required+nullable mixed oneOf to bare mixed with present+nullable rules (#8, NullableMixedOneOf)', function () {
    $code = conformanceCode('NullableMixedOneOfData');

    // The whole point of issue #8: `?mixed` does not compile, so a nullable
    // mixed-fallback must stay bare `mixed`. The php -l layer above also proves
    // it compiles, but pin the declaration here so a regression is loud. Because
    // the oneOf includes a type:"null" member, the property is required AND
    // nullable, so the rule is `present` + `nullable` (a present null is
    // accepted, a missing key is still rejected) rather than a bare `required`
    // that would false-reject a spec-valid present null.
    expect($code)->toContain('public readonly mixed $value')
        ->and($code)->not->toContain('?mixed')
        ->and($code)->toContain("'value' => ['present', 'nullable'],");
});

// --- Discriminated unions: inline + allOf-inheritance forms (#38) ----------

it('emits a morphable base plus synthesized variants for the INLINE-union discriminator form (#38)', function () {
    $files = conformance31();

    // The inline oneOf + discriminator becomes an abstract morphable base, and
    // each INLINE member becomes a synthesized variant Data class named from its
    // discriminator value (circle/square), extending the base.
    expect($files)->toHaveKey('InlineDiscriminatedUnionData')
        ->and($files)->toHaveKey('InlineDiscriminatedUnionCircleData')
        ->and($files)->toHaveKey('InlineDiscriminatedUnionSquareData');

    $base = $files['InlineDiscriminatedUnionData']->code;
    expect($base)->toContain('abstract class InlineDiscriminatedUnionData extends Data implements PropertyMorphableData')
        ->and($base)->toContain('#[PropertyForMorph, Required, StringType]')
        ->and($base)->toContain('public readonly string $shapeKind')
        ->and($base)->toContain("'circle' => InlineDiscriminatedUnionCircleData::class,")
        ->and($base)->toContain("'square' => InlineDiscriminatedUnionSquareData::class,")
        ->and($base)->toContain('default => null,');

    // A variant extends the base, forwards the discriminator, and pins its own
    // discriminator value (const) plus declares its own required field.
    $circle = $files['InlineDiscriminatedUnionCircleData']->code;
    expect($circle)->toContain('final class InlineDiscriminatedUnionCircleData extends InlineDiscriminatedUnionData')
        ->and($circle)->toContain('public readonly float $radius')
        ->and($circle)->toContain("'shapeKind' => [Rule::in(['circle'])],")
        ->and($circle)->toContain("'radius' => ['required', 'numeric'],")
        ->and($circle)->toContain('parent::__construct($shapeKind);');
});

it('emits a morphable base plus extending variants for the allOf-INHERITANCE discriminator form (#38)', function () {
    $files = conformance31();

    // The base declares the discriminator directly; the allOf variants extend it.
    expect($files)->toHaveKey('VehicleData')
        ->and($files)->toHaveKey('CarData')
        ->and($files)->toHaveKey('TruckData');

    $base = $files['VehicleData']->code;
    expect($base)->toContain('abstract class VehicleData extends Data implements PropertyMorphableData')
        ->and($base)->toContain('public readonly string $vehicleType')
        ->and($base)->toContain("'car' => CarData::class,")
        ->and($base)->toContain("'truck' => TruckData::class,");

    // A variant extends the base, forwards the discriminator, carries the base's
    // shared optional field (wheels) plus its own required allOf field (doors).
    $car = $files['CarData']->code;
    expect($car)->toContain('final class CarData extends VehicleData')
        ->and($car)->toContain('public readonly int $doors')
        ->and($car)->toContain('public readonly ?int $wheels = null')
        ->and($car)->toContain("'doors' => ['required', 'integer'],")
        ->and($car)->toContain('parent::__construct($vehicleType);');

    // A property typed by the allOf-inheritance base uses the abstract base type,
    // so spatie morphs it to the right variant at hydration.
    expect($files)->toHaveKey('VehicleHolderData');
    expect($files['VehicleHolderData']->code)->toContain('public readonly VehicleData $vehicle');
});

// --- Composition: allOf merge ----------------------------------------------

it('flattens allOf plus sibling properties into one class (AllOfWithSiblings)', function () {
    $code = conformanceCode('AllOfWithSiblingsData');

    // Merged ObjectWithRequired members plus the own sibling property.
    expect($code)->toContain('$id')
        ->and($code)->toContain('$name')
        ->and($code)->toContain('$sibling')
        ->and($code)->toContain("'sibling' => ['required', 'string'],");
});

// --- readOnly / writeOnly split --------------------------------------------

it('splits readOnly/writeOnly into a read class and a writable class (ReadWriteOnly)', function () {
    $files = conformance31();

    expect($files)->toHaveKey('ReadWriteOnlyData')
        ->and($files)->toHaveKey('ReadWriteOnlyWritableData');

    $read = $files['ReadWriteOnlyData']->code;
    $write = $files['ReadWriteOnlyWritableData']->code;

    // The read class keeps readOnly id and drops the writeOnly password.
    expect($read)->toContain('$id')
        ->and($read)->not->toContain('$password');

    // The writable class keeps the writeOnly password and drops the readOnly id.
    expect($write)->toContain('$password')
        ->and($write)->not->toContain('public readonly int $id');
});

// --- Defaults (#15) --------------------------------------------------------

it('seeds defaults into the constructor, including required-with-default and nullable-default (Defaults)', function () {
    $code = conformanceCode('DefaultsData');

    expect($code)->toContain("public readonly string \$optionalWithDefault = 'fallback'")
        // A required field with a default becomes `sometimes` and seeds the default.
        ->and($code)->toContain('public readonly int $withDefaultAndRequired = 0')
        ->and($code)->toContain("'withDefaultAndRequired' => ['sometimes', 'integer'],")
        ->and($code)->not->toContain("'withDefaultAndRequired' => ['required'")
        // A nullable default stays nullable and seeded.
        ->and($code)->toContain('public readonly ?string $nullableWithDefault = null');
});

// --- Naming torture --------------------------------------------------------

it('sanitizes torture property names without colliding or emitting invalid identifiers (NamingTortureProps)', function () {
    $code = conformanceCode('NamingTorturePropsData');

    // The reserved word, dotted, numeric-leading, and collide-pair names all
    // survive as distinct, valid PHP identifiers (the compile gate above already
    // proved the file is valid; pin the MapName round-trip here).
    expect($code)->toContain("#[MapName('this')]")
        ->and($code)->toContain('public readonly ?string $_this = null')
        ->and($code)->toContain("#[MapName('user.name')]")
        ->and($code)->toContain("#[MapName('2fast')]")
        // The PHP reserved word `class` is a legal property name, kept verbatim.
        ->and($code)->toContain('public readonly ?string $class = null')
        // The foo_bar / fooBar collide-after-sanitize pair gets distinct names.
        ->and($code)->toContain('public readonly ?string $fooBar_2 = null')
        ->and($code)->toContain('use Spatie\LaravelData\Attributes\MapName;');
});

it('sanitizes snake_case, dotted, and numeric-leading SCHEMA names to valid class names', function () {
    $classes = array_keys(conformance31());

    // snake_case_schema, dotted.schema.name, 9lives become valid Data classes.
    expect($classes)->toContain('SnakeCaseSchemaData')
        ->and($classes)->toContain('DottedSchemaNameData')
        ->and($classes)->toContain('_9livesData');
});

// --- Recursion / deep ref chains -------------------------------------------

it('emits a recursive self-referential schema and a deep ref chain (TreeNode, ChainA..C)', function () {
    $files = conformance31();

    expect($files)->toHaveKey('TreeNodeData')
        ->and($files)->toHaveKey('ChainAData')
        ->and($files)->toHaveKey('ChainBData')
        ->and($files)->toHaveKey('ChainCData');

    // The recursive node references its own collection type.
    expect($files['TreeNodeData']->code)->toContain('TreeNodeData')
        // The chain links resolve A -> B -> C.
        ->and($files['ChainAData']->code)->toContain('ChainBData')
        ->and($files['ChainBData']->code)->toContain('ChainCData');
});

// --- 3.0 nullable spellings ------------------------------------------------

it('keeps 3.0 nullable:true objects and enums valid (conformance-3.0-forms)', function () {
    $files = conformance30();

    // A nullable object keeps nullable members; a nullable enum is still a clean
    // backed enum with no null case.
    expect($files)->toHaveKey('NullableObjectData')
        ->and($files)->toHaveKey('NullableEnum');

    // The enum NAME contains "Null", so assert on the absence of a null case
    // rather than the substring.
    expect($files['NullableEnum']->code)->toContain('enum NullableEnum: string')
        ->and($files['NullableEnum']->code)->not->toContain('= null')
        ->and($files['NullableEnum']->code)->not->toContain("= 'null'");
});

// ===========================================================================
// LAYER 3: DETERMINISM
// ===========================================================================

it('generates byte-identical output on a second run (determinism), both documents', function (string $path) {
    $document1 = (new SpecParser)->parseFile($path);
    $first = array_map(fn (GeneratedFile $f) => $f->code, (new ModelGenerator)->generate($document1));

    $document2 = (new SpecParser)->parseFile($path);
    $second = array_map(fn (GeneratedFile $f) => $f->code, (new ModelGenerator)->generate($document2));

    expect($first)->toBe($second);
})->with([
    'conformance-3.1' => [CONFORMANCE_31],
    'conformance-3.0-forms' => [CONFORMANCE_30],
]);
