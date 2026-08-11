<?php

declare(strict_types=1);

use App\Data\Pet\ListPetsQueryData;
use App\Data\Widget\CreateWidgetQueryData;
use App\Data\Widget\FilterWidgetsQueryData;
use App\Data\Widget\ListWidgetsQueryData;
use App\Data\Widget\LookupWidgetsQueryData;
use App\Data\Widget\SearchWidgetsQueryData;
use App\Data\Widget\ToggleWidgetsQueryData;
use App\Data\Widget\WidgetState;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * End-to-end for the per-operation query Data classes (issue #63): generate
 * from a spec with `in: query` parameters, load the emitted classes into the
 * booted Laravel app, and drive `fromQuery()` with REAL Request objects, so
 * the payloads are exactly what PHP's query-string parsing produces (scalars
 * arrive as strings, `ids[]=` arrives as an array). Proves the generated
 * rules() accept and reject through the actual Laravel validator, and that
 * hydration is query-only: request-body fields never bleed in.
 */
beforeEach(function () {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $dir = sys_get_temp_dir().'/oal_query_roundtrip_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach (['query-parameters.yaml', 'petstore.yaml'] as $fixture) {
        $parser104 = new SpecParser;
        $document = $parser104->parseFileToDocument(__DIR__.'/../../Fixtures/server/'.$fixture);
        $generator = new ModelGenerator;
        $files = $generator->generate($document);
        // The collector emits the query classes as a side effect, exactly like
        // the planner wiring.
        (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($document);

        // loadGeneratedFiles skips classes another suite already loaded (the
        // status-code round-trip loads the same petstore Data set; the
        // generator is deterministic, so the definitions are byte-identical).
        loadGeneratedFiles($dir, [...array_values($files), ...array_values($generator->queryFiles())]);
    }
});

/**
 * The server descriptor for one operation of the query-parameters fixture,
 * memoized. The round-trip tests need the generator's INJECTION DECISION, not
 * just the emitted classes, so a test can exercise exactly the wiring the
 * scaffold emits instead of assuming one.
 */
function queryRoundTripDescriptor(string $method, string $path): OperationDescriptor
{
    static $descriptors = null;

    if ($descriptors === null) {
        $parser104 = new SpecParser;
        $document = $parser104->parseFileToDocument(__DIR__.'/../../Fixtures/server/query-parameters.yaml');
        $generator = new ModelGenerator;
        $generator->generate($document);
        $descriptors = (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($document);
    }

    foreach ($descriptors as $descriptor) {
        if ($descriptor->httpMethod === $method && $descriptor->path === $path) {
            return $descriptor;
        }
    }

    throw new RuntimeException("No descriptor for {$method} {$path}");
}

it('hydrates typed properties from a real query string', function () {
    $request = Request::create('/widgets?state=used&page=2&ids[]=3&ids[]=4&q=ab', 'GET');

    $query = ListWidgetsQueryData::fromQuery($request);

    expect($query->state)->toBe(WidgetState::Used)
        ->and($query->page)->toBe(2)
        ->and($query->q)->toBe('ab')
        ->and($query->ids)->toHaveCount(2);
});

it('rejects a missing required query parameter', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets', 'GET'));
})->throws(ValidationException::class);

it('rejects an enum value outside the spec set', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=shiny', 'GET'));
})->throws(ValidationException::class);

it('rejects an integer below the spec minimum', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=new&page=0', 'GET'));
})->throws(ValidationException::class);

it('rejects a non-integer value for an integer parameter', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=new&page=soon', 'GET'));
})->throws(ValidationException::class);

it('rejects a string shorter than minLength', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=new&q=a', 'GET'));
})->throws(ValidationException::class);

it('rejects a scalar where the spec declares an array parameter', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=new&ids=3', 'GET'));
})->throws(ValidationException::class);

it('rejects an array element violating the item constraints', function () {
    ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=new&ids[]=0', 'GET'));
})->throws(ValidationException::class);

it('treats absent optional parameters as null', function () {
    $query = ListWidgetsQueryData::fromQuery(Request::create('/widgets?state=new', 'GET'));

    expect($query->page)->toBeNull()
        ->and($query->ids)->toBeNull()
        ->and($query->q)->toBeNull();
});

it('fills a spec default when the parameter is omitted', function () {
    // petstore.yaml: GET /pets declares limit (integer, default 20) and a
    // required status enum.
    $query = ListPetsQueryData::fromQuery(Request::create('/pets?status=sold', 'GET'));

    expect($query->limit)->toBe(20)
        ->and($query->status)->toBe('sold');
});

it('hydrates from the query string only: request-body fields never bleed in', function () {
    // The POST body smuggles the required query field; the query string itself
    // is missing it, so validation must fail even though $request->all() holds
    // a passing merged payload.
    $request = Request::create('/widgets', 'POST', ['state' => 'new']);
    expect($request->all())->toHaveKey('state');

    ListWidgetsQueryData::fromQuery($request);
})->throws(ValidationException::class);

it('routes container injection through the query-only factory', function () {
    // The abstract controllers type-hint the query class for body-less
    // operations; laravel-data resolves it via `::from($container['request'])`,
    // which picks fromQuery() as the magic creation method. Prove the injected
    // path validates and hydrates from the query string only.
    $this->app->instance('request', Request::create('/widgets?state=broken&page=7', 'GET'));

    $query = $this->app->make(ListWidgetsQueryData::class);

    expect($query->state)->toBe(WidgetState::Broken)
        ->and($query->page)->toBe(7);
});

it('rejects an invalid query on the container-injection path too', function () {
    $this->app->instance('request', Request::create('/widgets?state=new&page=0', 'GET'));

    $this->app->make(ListWidgetsQueryData::class);
})->throws(ValidationException::class);

it('ignores body values when the same name exists in body and query', function () {
    // POST /widgets carries a Widget body; its CreateWidgetQueryData is the
    // fromQuery-only class (not injected). The body's conflicting junk value
    // for validateOnly must not reach query validation.
    $request = Request::create('/widgets?validateOnly=1', 'POST', ['validateOnly' => 'banana', 'name' => 'w']);

    $query = CreateWidgetQueryData::fromQuery($request);

    expect($query->validateOnly)->toBeTrue();
});

it('accepts and hydrates 1/0 boolean query values', function () {
    $on = CreateWidgetQueryData::fromQuery(Request::create('/widgets?validateOnly=1', 'POST'));
    $off = CreateWidgetQueryData::fromQuery(Request::create('/widgets?validateOnly=0', 'POST'));

    expect($on->validateOnly)->toBeTrue()
        ->and($off->validateOnly)->toBeFalse();
});

it('accepts and hydrates literal true/false boolean query values', function () {
    // ?flag=true is how the OpenAPI form style serializes booleans, so the
    // generated rules must accept the literal strings and hydrate them to the
    // right bool (never the PHP "non-empty string is truthy" coercion).
    $on = CreateWidgetQueryData::fromQuery(Request::create('/widgets?validateOnly=true', 'POST'));
    $off = CreateWidgetQueryData::fromQuery(Request::create('/widgets?validateOnly=false', 'POST'));

    expect($on->validateOnly)->toBeTrue()
        ->and($off->validateOnly)->toBeFalse();
});

it('rejects a non-boolean value for a boolean parameter', function () {
    CreateWidgetQueryData::fromQuery(Request::create('/widgets?validateOnly=banana', 'POST'));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Delimited (non-exploded) array query parameters (issue #132): the factory
// splits the single joined string on its delimiter, then the array element
// rules validate the items through the real Laravel validator.
// ---------------------------------------------------------------------------

it('splits a comma-delimited (form + explode: false) array and validates the items', function () {
    // ?csv=a,b,c arrives as one string; the factory splits it into ['a','b','c'].
    $query = SearchWidgetsQueryData::fromQuery(Request::create('/search?csv=a,b,c', 'GET'));

    expect($query->csv)->toBe(['a', 'b', 'c']);
});

it('splits a space-delimited array and validates the items', function () {
    // ?tags=aa+bb+cc decodes to "aa bb cc"; the factory splits on the space.
    $query = SearchWidgetsQueryData::fromQuery(Request::create('/search?tags=aa%20bb%20cc', 'GET'));

    expect($query->tags)->toBe(['aa', 'bb', 'cc']);
});

it('splits a pipe-delimited array and validates the items', function () {
    $query = SearchWidgetsQueryData::fromQuery(Request::create('/search?matrix=a|b|c', 'GET'));

    expect($query->matrix)->toBe(['a', 'b', 'c']);
});

it('rejects a delimited array item that violates the per-item constraints', function () {
    // tags items require minLength 2; the second split item "b" is too short, so
    // the real Laravel validator rejects the split array.
    SearchWidgetsQueryData::fromQuery(Request::create('/search?tags=aa%20b', 'GET'));
})->throws(ValidationException::class);

it('splits an empty-string delimited value to a single empty-string element', function () {
    // ?tags= is a PRESENT key with an empty value: PHP explode("", ...) yields a
    // single empty-string element [""], so the array IS present (minItems 1 is
    // satisfied with one element). Laravel treats an empty string as absent for
    // non-required item rules, so the per-item minLength is not enforced on it.
    // This is explode()'s natural behavior; an absent key (no split) stays null.
    $query = SearchWidgetsQueryData::fromQuery(Request::create('/search?tags=', 'GET'));

    expect($query->tags)->toBe(['']);
});

it('treats an absent delimited array as null (not an empty array)', function () {
    $query = SearchWidgetsQueryData::fromQuery(Request::create('/search', 'GET'));

    expect($query->csv)->toBeNull()
        ->and($query->matrix)->toBeNull()
        ->and($query->tags)->toBeNull();
});

it('splits the delimited array AND maps the boolean in one operation', function () {
    // FilterWidgets has both kinds (comma-delimited int array) and active (bool).
    $query = FilterWidgetsQueryData::fromQuery(Request::create('/filter?kinds=1,2,3&active=true', 'GET'));

    // laravel-data keeps array<int, int> elements as their wire strings (the same
    // as the existing exploded ?ids[]= path); the `integer` item rule still
    // validates them. The bool literal is mapped and hydrated.
    expect($query->kinds)->toBe(['1', '2', '3'])
        ->and($query->active)->toBeTrue();
});

it('rejects a delimited int array whose split item is below the spec minimum', function () {
    FilterWidgetsQueryData::fromQuery(Request::create('/filter?kinds=1,0,3', 'GET'));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// deepObject object query parameters (issue #131): ?range[gte]=10&range[lte]=20
// parses NATIVELY into ['range' => ['gte' => '10', 'lte' => '20']], so the
// nested Data class validates and hydrates the nested values with NO manual
// splitting (unlike the delimited-array case above).
// ---------------------------------------------------------------------------

it('hydrates a deepObject parameter into a nested Data class from a real query string', function () {
    // The bracketed keys are parsed by PHP into a nested array before fromQuery()
    // ever sees them, so the nested SearchWidgetsQueryFilterData hydrates directly.
    $request = Request::create('/search?filter[gte]=10&filter[lte]=20&filter[color]=blue', 'GET');

    $query = SearchWidgetsQueryData::fromQuery($request);

    expect($query->filter)->not->toBeNull()
        ->and($query->filter->gte)->toBe(10)
        ->and($query->filter->lte)->toBe(20)
        ->and($query->filter->color)->toBe('blue');
});

it('treats an absent deepObject parameter as null', function () {
    $query = SearchWidgetsQueryData::fromQuery(Request::create('/search', 'GET'));

    expect($query->filter)->toBeNull();
});

it('rejects a deepObject nested value that violates the per-property constraints', function () {
    // filter[gte] has minimum 0; -1 must fail through the nested-class rules,
    // validated as the dotted filter.gte path by the real Laravel validator.
    SearchWidgetsQueryData::fromQuery(Request::create('/search?filter[gte]=-1', 'GET'));
})->throws(ValidationException::class);

it('rejects a deepObject nested string shorter than its minLength', function () {
    SearchWidgetsQueryData::fromQuery(Request::create('/search?filter[color]=x', 'GET'));
})->throws(ValidationException::class);

it('routes a deepObject-only body-less GET through container injection (issue #131)', function () {
    // GET /lookup is body-less with ONLY a deepObject parameter, so its query
    // class stays container-injectable (the native nested array validates fine,
    // no pre-split needed). Prove the injected path hydrates the nested values.
    $this->app->instance('request', Request::create('/lookup?range[gte]=5&range[lte]=9', 'GET'));

    $query = $this->app->make(LookupWidgetsQueryData::class);

    expect($query->range)->not->toBeNull()
        ->and($query->range->gte)->toBe(5)
        ->and($query->range->lte)->toBe(9);
});

it('rejects an invalid deepObject value on the container-injection path too', function () {
    $this->app->instance('request', Request::create('/lookup?range[gte]=-3', 'GET'));

    $this->app->make(LookupWidgetsQueryData::class);
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Boolean query parameters on a BODY-LESS operation (issue #172). The literal
// mapping in fromQuery() has always worked when called directly, but a
// body-less boolean operation used to be container-injected, and under
// injection spatie validates the RAW request before it calls the magic
// fromQuery() creation method (DataFromSomethingResolver runs the pipeline
// "solely for the purpose of validation"). Laravel's `boolean` rule accepts
// true/false/1/0/"1"/"0" but NOT the literals "true"/"false", so the mapping
// never ran and a spec-valid ?flag=true was a 422. GET /toggle isolates the
// case: body-less, no delimited array, no deepObject, booleans only.
// ---------------------------------------------------------------------------

it('hydrates ?flag=true through the wiring the generator actually chose (issue #172)', function () {
    // Deliberately driven by the descriptor rather than assuming a path: the
    // test asserts that whatever wiring the scaffold emits for this operation
    // hydrates a spec-valid request. Before #172 the generator chose container
    // injection, which 422s here; after it, the additive ::fromQuery() path.
    $toggle = queryRoundTripDescriptor('get', '/toggle');
    $request = Request::create('/toggle?flag=true&verbose=true', 'GET');

    if ($toggle->queryParam['injected']) {
        $this->app->instance('request', $request);
        $query = $this->app->make(ToggleWidgetsQueryData::class);
    } else {
        $query = ToggleWidgetsQueryData::fromQuery($request);
    }

    expect($query->flag)->toBeTrue()
        ->and($query->verbose)->toBeTrue();
});

it('hydrates ?flag=false through the wiring the generator actually chose (issue #172)', function () {
    $toggle = queryRoundTripDescriptor('get', '/toggle');
    $request = Request::create('/toggle?flag=false', 'GET');

    if ($toggle->queryParam['injected']) {
        $this->app->instance('request', $request);
        $query = $this->app->make(ToggleWidgetsQueryData::class);
    } else {
        $query = ToggleWidgetsQueryData::fromQuery($request);
    }

    // The literal "false" must hydrate to FALSE, never to PHP's truthy
    // non-empty-string coercion.
    expect($query->flag)->toBeFalse()
        // An absent defaulted boolean takes its spec default.
        ->and($query->verbose)->toBeFalse();
});

it('applies the spec default when a defaulted boolean is absent (issue #172)', function () {
    $query = ToggleWidgetsQueryData::fromQuery(Request::create('/toggle', 'GET'));

    expect($query->verbose)->toBeFalse()
        ->and($query->flag)->toBeNull();
});

it('still rejects a non-boolean value on the additive boolean path (issue #172)', function () {
    ToggleWidgetsQueryData::fromQuery(Request::create('/toggle?flag=banana', 'GET'));
})->throws(ValidationException::class);

it('documents WHY a boolean query class must not be container-injected (issue #172)', function () {
    // The mechanism proof, and a canary. Resolving the class through the
    // container is what an injected controller parameter does: spatie validates
    // the raw "true" string against the `boolean` rule and rejects it before
    // fromQuery() can map the literal. This is precisely the 422 the additive
    // forcing avoids. If a future spatie/Laravel release stops rejecting the
    // literals, this test goes red and the forcing can be reconsidered.
    $this->app->instance('request', Request::create('/toggle?flag=true', 'GET'));

    $this->app->make(ToggleWidgetsQueryData::class);
})->throws(ValidationException::class);
