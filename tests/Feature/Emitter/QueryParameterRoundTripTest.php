<?php

declare(strict_types=1);

use App\Data\Pet\ListPetsQueryData;
use App\Data\Widget\CreateWidgetQueryData;
use App\Data\Widget\ListWidgetsQueryData;
use App\Data\Widget\WidgetState;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
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
