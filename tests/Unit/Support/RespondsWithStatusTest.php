<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Support\RespondsWithStatus;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Direct unit coverage for the status-enforcing middleware (issue #64, #125).
 *
 * The middleware is attached only to routes whose spec declares a non-200
 * success status, so its job is to normalize the framework-default success
 * status (which is NOT always 200) to the declared one while leaving error
 * responses untouched. The headline regression is #125: spatie/laravel-data
 * serializes a Data object returned from a POST as 201 Created, so a guard on
 * exactly-200 dropped a declared 202 on every mutating Data-returning op.
 */
function runRespondsWithStatus(int $incoming, string $declared): Response
{
    $middleware = new RespondsWithStatus;

    return $middleware->handle(
        Request::create('/x', 'POST'),
        fn (Request $request): Response => new Response('{"id":7}', $incoming),
        $declared,
    );
}

it('rewrites an already-201 Data response to the declared 202 (issue #125)', function () {
    // laravel-data serializes a POST Data return as 201; the declared status is
    // 202, and before #125 the exactly-200 guard left the 201 in place.
    $response = runRespondsWithStatus(201, '202');

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('{"id":7}');
});

it('rewrites a framework-default 200 to the declared status', function () {
    expect(runRespondsWithStatus(200, '202')->getStatusCode())->toBe(202);
});

it('leaves a legitimately-201 create at 201 when the spec declares 201', function () {
    // The middleware is parameterized with the declared status, so a genuine
    // 201 spec attaches ':201' and the rewrite is a no-op.
    $response = runRespondsWithStatus(201, '201');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('{"id":7}');
});

it('clears the body when normalizing to 204 No Content', function () {
    $response = runRespondsWithStatus(200, '204');

    expect($response->getStatusCode())->toBe(204)
        ->and($response->getContent())->toBe('');
});

it('never promotes an error response', function (int $error) {
    // A spec-derived 422, a 404, a 500: the success rewrite must not touch any
    // of them, whatever success status the route declares.
    expect(runRespondsWithStatus($error, '202')->getStatusCode())->toBe($error);
})->with([422, 404, 500]);

it('leaves a 3xx redirect untouched', function () {
    // Only a 2xx framework-default success status is rewritten; a deliberate
    // redirect set by the handler passes through.
    expect(runRespondsWithStatus(302, '202')->getStatusCode())->toBe(302);
});
