# Petstore demo

An end to end showcase of `codewithagents/openapi-laravel`: from one OpenAPI
spec to generated models, generated abstract controllers, and a generated routes
file, with hand-written concrete controllers wiring the business logic.

This demo is for illustration and tests only. It is excluded from the published
Composer package (see `/examples export-ignore` in `.gitattributes`), so it never
ships to people who `composer require` the library.

## What is in here

```
openapi.yaml                              the spec (copy of the petstore-3.0 fixture)
Data/*.php                                GENERATED Data classes (spatie/laravel-data)
Http/Controllers/Api/Abstract*.php        GENERATED abstract controllers (one per tag)
Http/Controllers/Api/{Pet,Store,User}Controller.php   HAND-WRITTEN concrete controllers
Http/Controllers/Api/InMemoryStore.php    HAND-WRITTEN in-memory backing store (stands in for a DB)
routes/api.generated.php                  GENERATED route table (one line per operation)
```

Everything in `Data/`, every `Abstract*Controller.php`, and `routes/api.generated.php`
is verbatim generator output. Do not hand-edit them. The only hand-written PHP is
the three concrete controllers and the in-memory store.

## How the files were generated

The committed generated files were produced by running the standalone binary
against `openapi.yaml`, from the repository root:

```bash
php bin/openapi-laravel \
  --spec=examples/petstore/openapi.yaml \
  --output=examples/petstore/Data \
  --namespace="CodeWithAgents\\OpenApiLaravel\\Examples\\Petstore\\Data" \
  --controllers \
  --controller-output=examples/petstore/Http/Controllers/Api \
  --controller-namespace="CodeWithAgents\\OpenApiLaravel\\Examples\\Petstore\\Http\\Controllers\\Api" \
  --routes \
  --routes-output=examples/petstore/routes/api.generated.php
```

The drift test (`tests/Feature/Example/PetstoreDriftTest.php`) re-runs this
generation into a temp dir and asserts the output is byte-identical to the
committed files, which proves the demo is genuinely generated and deterministic.

## How the concrete controllers extend the generated abstracts

Each generated abstract controller declares one `abstract` method per spec
operation, typed against the generated Data classes (typed body, query, and
path params in, Data or DataCollection or JsonResponse out). Operations with
`in: query` parameters get a per-operation query Data class (issue #63):
body-less operations receive it as a typed, container-validated method
parameter (e.g. `findPetsByStatus(FindPetsByStatusQueryData $query)`), and
operations with a request body call `<Operation>QueryData::fromQuery($request)`
explicitly, as the generated docblock points out. A concrete controller simply
extends it and implements every abstract method:

```php
final class PetController extends AbstractPetController
{
    public function addPet(PetData $pet): PetData
    {
        // $pet arrives already hydrated and validated against the spec rules().
        return $this->store->putPet($pet);
    }
    // ...one method per operation
}
```

Because the abstracts are abstract, forgetting to implement an operation is a PHP
fatal, not silent drift. And because the route table is generated from the spec,
the set of routes can never drift from the contract either.

## What the feature tests prove

`tests/Feature/Example/PetstoreDemoTest.php` boots an Orchestra Testbench app,
loads `routes/api.generated.php`, and exercises the full chain:

- `POST /pet` with a valid body returns the typed pet (laravel-data hydration +
  Responsable output).
- `POST /pet` with a body missing required fields returns 422 from the
  spec-derived `rules()`, with no hand-written validation. This is the headline.
- `GET /pet/{petId}`, `GET /pet/findByStatus`, and one Store and one User call
  exercise all three controllers and the DataCollection return path.
