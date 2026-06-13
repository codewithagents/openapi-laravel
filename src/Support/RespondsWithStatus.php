<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

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
 * Any framework-default 2xx response is rewritten: a controller action
 * returns a plain Data object (or nothing, for a 204) and lets the framework
 * pick the success status, and that default is not always 200. Laravel /
 * spatie/laravel-data serializes a Data object returned from a POST as 201
 * Created (issue #125), so guarding on exactly-200 would silently drop a
 * declared 202 (or any other non-201 success) on a mutating Data-returning
 * operation. Rewriting any 2xx covers both the 200 default (GET, void
 * handlers) and the 201 default (POST resources) while leaving every error
 * response (404, 422, 500, ...) untouched, and a handler that explicitly set
 * a non-2xx status of its own keeps it.
 *
 * A spec that genuinely declares 201 attaches this middleware with `:201`, so
 * normalizing an already-201 response to 201 is a no-op: the legitimate
 * create still answers 201. For 204 No Content the body is cleared as well,
 * because RFC 9110 forbids content on a 204.
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

        // Rewrite only a framework-default success status (any 2xx); error
        // responses pass through so a spec-derived 422, a 404, etc. is never
        // promoted to the declared success code.
        $current = $response->getStatusCode();
        if ($current >= 200 && $current < 300) {
            $code = (int) $status;
            $response->setStatusCode($code);

            if ($code === Response::HTTP_NO_CONTENT) {
                $response->setContent('');
            }
        }

        return $response;
    }
}
