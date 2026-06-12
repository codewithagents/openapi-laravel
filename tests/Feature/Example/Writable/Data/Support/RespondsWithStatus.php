<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware that applies the spec-declared success status (issue #64).
 *
 * A generated controller action returns a Data object (or, for a 204
 * operation, nothing), which Laravel serializes with its default 200 status.
 * When the OpenAPI contract declares a different success status (201, 202,
 * 204, ...), the generated routes file attaches this middleware with that
 * code as a parameter (`RespondsWithStatus::class.':201'`), so the scaffold
 * honors the contract without hand-written glue.
 *
 * Only an exactly-200 response is rewritten: an error response (404, 422,
 * 500, ...) passes through untouched, and a handler that explicitly set a
 * non-200 status of its own keeps it. For 204 No Content the body is cleared
 * as well, because RFC 9110 forbids content on a 204.
 */
final class RespondsWithStatus
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $status  the spec-declared success status, passed as a route middleware parameter
     */
    public function handle(Request $request, Closure $next, string $status): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_OK) {
            $code = (int) $status;
            $response->setStatusCode($code);

            if ($code === Response::HTTP_NO_CONTENT) {
                $response->setContent('');
            }
        }

        return $response;
    }
}
