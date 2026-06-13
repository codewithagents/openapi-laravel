# Conformance fixtures

Single golden regression corpus for the openapi-laravel generator. These specs
are NOT realistic APIs. They are kitchen-sink conformance documents whose only
job is to exercise every construct the generator must handle, so generated
output can be asserted construct by construct in one place.

Two files:

- `conformance-3.1.yaml` , the main document. OpenAPI 3.1.0. One named component
  schema or operation per construct.
- `conformance-3.0-forms.yaml` , a small OpenAPI 3.0.x companion covering ONLY
  the 3.0-specific spellings that cannot legally appear in a 3.1 document
  (`nullable: true`, boolean `exclusiveMinimum`/`exclusiveMaximum`).

These files are deliberately NOT in the `tests/Fixtures/specs/` corpus glob, so
they do not auto-run through the existing 128-spec parse and generate gates. The
golden snapshot test that consumes them is a later capstone, landing after the
in-flight generator fixes.

## Issue legend

| Issue | Meaning |
|-------|---------|
| #8  | `?mixed` non-compiling (required + nullable oneOf resolving to mixed) |
| #9  | Non-object component aliases ($ref to a scalar/array/oneOf alias) |
| #10 | Exclusive bounds (numeric exclusiveMinimum/exclusiveMaximum) |
| #11 | Float/number enums |
| #12 | Multi-type arrays with two real types |
| #13 | date-time rule emission |
| #14 | multipleOf / uniqueItems |
| #15 | Defaults (including nullable default) |
| #18 | Documented no-op string formats |

## Parser-gap finding (resolved, issue #20)

The vendored cebe parser (`devizzent/cebe-php-openapi`) cannot instantiate a
boolean schema value, so the canonical closed-tuple spelling `items: false`
(and `items: true`) used to be rejected with `Unable to instantiate Schema
Object with data ''`. The `SchemaNormalizer` (issue #20, on main) now rewrites a
boolean `items` value before cebe sees it: `items: true` becomes `items: {}` and
`items: false` is dropped. `TupleSchema` therefore carries the canonical
`items: false` closed-tuple spelling again and parses cleanly. Boolean
`additionalProperties: true|false` was always accepted (cebe special-cases that
path), so those constructs are unchanged.

Both files parse cleanly through `SpecParser` (and pass cebe's optional
`validate()` pass): `conformance-3.1.yaml` and `conformance-3.0-forms.yaml`.

## conformance-3.1.yaml manifest

### Scalars and formats

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `Scalars` | string, integer int32, integer int64, number float, number double, boolean | |
| `StringFormats` | date, date-time, time, duration, email, uuid, uri, hostname, ipv4, ipv6, byte, binary, password, custom/unknown format | #13, #18 |
| `StringConstraints` | minLength, maxLength, pattern containing both `#` and `~` | |
| `NumericConstraints` | minimum, maximum, numeric exclusiveMinimum/exclusiveMaximum (3.1), multipleOf | #10, #14 |

### Arrays

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `ArrayScalar` | scalar items, minItems, maxItems, uniqueItems | #14 |
| `ArrayOfRef` | items via $ref | |
| `TupleSchema` | closed prefixItems tuple plus `items: false` | #20 |
| `ArrayOfArray` | array of array | #62 |
| `ArrayOfUnion` | array whose items are a oneOf union | |
| `ArrayOfEnum` | array whose items are a backed enum (no DataCollectionOf) | #62 |

### Objects and additionalProperties

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `ObjectWithRequired` | properties, required, minProperties, maxProperties | |
| `DependentRequiredObject` | dependentRequired (required_with on the dependent, present_with when nullable, merged triggers) | #81 |
| `AdditionalPropsFalse` | additionalProperties false | |
| `AdditionalPropsTrue` | additionalProperties true | |
| `AdditionalPropsScalar` | additionalProperties scalar-value schema (map of string) | |
| `AdditionalPropsRef` | additionalProperties $ref-value schema (map of object) | |
| `MixedObject` | named properties PLUS additionalProperties | |
| `MapOfObjects` | map of objects | |

### Nullability

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `Nullability` | 3.1 type-array `[string,null]`; two-real-type `[string,integer]`; `[string,integer,null]` | #12 |

### Enums

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `StringEnum` | string enum | |
| `IntegerEnum` | integer enum | |
| `FloatEnum` | number/float enum | #11 |
| `BooleanEnum` | boolean enum | |
| `MixedTypeEnum` | mixed-type enum | |
| `EnumWithNull` | enum containing null | |
| `SingleValueEnum` | single-value enum | |
| `ConstSchema` | const | |

### Composition

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `AllOfPure` | pure allOf | |
| `AllOfWithSiblings` | allOf plus own sibling properties | |
| `AllOfNested` | nested allOf | |
| `AllOfWithRef` | allOf with a $ref branch | |
| `OneOfScalars` | oneOf of scalars (scalar union) | |
| `OneOfDiscriminated` | oneOf of $ref objects with discriminator + mapping | |
| `OneOfNoDiscriminator` | oneOf of $ref objects without discriminator | |
| `NullableMixedOneOf` | required + nullable oneOf resolving to mixed (`?mixed`) | #8 |
| `AnyOfSchema` | anyOf | |

### $ref targets

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `ScalarAlias` | top-level scalar alias (non-object component) | #9 |
| `ArrayAlias` | top-level array alias (non-object component) | #9 |
| `OneOfAlias` | top-level oneOf alias (non-object component) | #9 |
| `TreeNode` | recursive / self-referential schema | |
| `ChainA` / `ChainB` / `ChainC` | deeply nested ref chain | |
| `Widget` | plain object, common $ref target | |

### readOnly / writeOnly

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `ReadWriteOnly` | readOnly/writeOnly at top level, on a nested object property, and on array items | |

### Defaults

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `Defaults` | default on optional field, default on a required field, default on an enum, nullable default | #15 |

### Naming torture

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `NamingTortureProps` | property names: snake_case, PHP reserved word (`class`), `this`, numeric-leading (`2fast`), unicode (`naïve_café`), dotted (`user.name`), and a `foo_bar`/`fooBar` collide-after-sanitize pair | |
| `snake_case_schema` | snake_case schema NAME | |
| `dotted.schema.name` | dotted schema NAME | |
| `9lives` | numeric-leading schema NAME | |

### Use-site exerciser

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `Exerciser` | references the non-object alias/array/map components (scalar map, ref map, map of objects, scalar array + uniqueItems, array of $ref, array of union, array of array, tuple, scalar alias, array alias, oneOf-of-scalars alias, scalar union, object union, recursive TreeNode) as named properties | #9 |

By issue #9 a top-level non-object component (a scalar, an array, a map, a
oneOf union) is inlined at its use site rather than emitted as its own (empty)
Data class. Its generated shape (typed maps with the `MapObjectTransformer`,
`DataCollection`s, the uniqueItems `distinct` rule, tuple/array element types,
scalar-alias rules, native scalar unions, and the `mixed`-typed object union with
its variant docblock per issue #31) is therefore only observable
where it is REFERENCED. `Exerciser` references one of each so the golden test
can assert that inlined output on real generated code; it is a construct
exerciser, not a realistic payload.

### Supporting schemas

`GadgetAlpha`, `GadgetBeta` (discriminated union members), `GadgetInput`,
`GizmoInput`, `Gizmo`, `ErrorObject` support the operations and unions above.

## Known latent issue surfaced by the exerciser

`ArrayOfUnion` (an array whose `items` are a `oneOf` of two $ref objects)
generates a `DataCollectionOf` attribute argument that is NOT a single class
reference: `#[DataCollectionOf(GadgetAlphaData|GadgetBetaData::class)]`. PHP
operator precedence parses `Foo|Bar::class` as `Foo | (Bar::class)`, so `php -l`
accepts it, but it is semantically wrong (a union is not a valid
`DataCollectionOf` target). The golden test asserts only the correct part (the
`@var array<int, GadgetAlphaData|GadgetBetaData>` docblock) and does NOT pin the
broken attribute, so the test stays green while the bug is tracked. Fixing it
lives in `src/` (out of scope for the test layer).

### Operations

| Operation (path) | Constructs | Issues |
|------------------|-----------|--------|
| `getWidget` (`GET /widgets/{widgetId}`) | integer path param, query param, header param, cookie param, 200 JSON with a response header (dropped with a warning), default response | #114 |
| `createWidget` (`POST /widgets`) | requestBody inline schema, 201 created, operation callback (dropped with a warning) | #115 |
| `deleteAllWidgets` (`DELETE /widgets`) | 204 no content | |
| `createGadget` (`POST /gadgets`) | requestBody $ref to component schema, response oneOf of $ref objects, 422 with no content schema | |
| `createGizmo` (`POST /gizmos`) | requestBody $ref to a component requestBody (resolved to the wrapped schema's Data class) | #110 |
| `getGizmo` (`GET /gizmos/{gizmoId}`) | 200 response $ref to a component response wrapping a schema $ref (reuses the existing Data class) | #116 |
| `deleteGizmo` (`DELETE /gizmos/{gizmoId}`) | 204 response $ref to a body-less component response (stays void) | #116 |
| `getGizmoSummary` (`GET /gizmos/summary`) | 200 response $ref to a component response with an inline object schema (synthesizes the shared `GizmoSummaryResponseData`) | #116 |
| `downloadWidgetBlob` (`GET /widgets/{widgetId}/blob`) | non-JSON binary response (application/octet-stream), typed as the base Symfony Response with a warning | #118 |
| `getReport` (`GET /report`) | non-JSON text response (text/html), typed as the base Symfony Response with a warning | #117 |
| `uploadStuff` (`POST /uploads`) | multipart/form-data body, application/x-www-form-urlencoded body | |
| (no id) (`GET /pingless`) | operation with no operationId, multiple tags | |
| `duplicateOp` (`GET /collide/first`) | operationId collision part 1 | |
| `duplicateOp` (`GET /collide/second`) | operationId collision part 2 (same id, different path) | |

## conformance-3.0-forms.yaml manifest

| Schema | Constructs | Issues |
|--------|-----------|--------|
| `NullableScalar` | 3.0 `nullable: true` on a scalar | |
| `NullableObject` | 3.0 `nullable: true` on an object | |
| `NullableEnum` | 3.0 `nullable: true` on an enum | |
| `BooleanExclusiveBounds` | 3.0 boolean `exclusiveMinimum: true` with `minimum`, boolean `exclusiveMaximum: true` with `maximum` | #10 |

| Operation (path) | Constructs |
|------------------|-----------|
| `ping30` (`GET /ping`) | trivial operation so the 3.0 document is structurally complete |
