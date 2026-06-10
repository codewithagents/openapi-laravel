<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force JSON content negotiation for all API routes.
 *
 * The generated openapi-zod-ts client does not send an Accept header, so the
 * browser's default Accept is "text/html,application/xhtml+xml,...". Without
 * this middleware, Laravel's $request->wantsJson() returns false, and the
 * error/exception path produces an HTML redirect (302) instead of a JSON
 * response (422). This middleware sets Accept: application/json on every
 * inbound API request so that $request->wantsJson() is always true and all
 * error responses come back as JSON regardless of what the client advertised.
 *
 * This is a demo wiring concern, not a bug in the generated Laravel code. The
 * standard fix for a real app is to mount generated API routes under the built-
 * in 'api' middleware group (which includes SubstituteBindings) combined with a
 * JsonAccept guard, or to add this middleware to the 'api' group. The missing
 * Accept header is a known gap in the openapi-zod-ts generated client and
 * should be fixed upstream in that generator.
 */
final class ForceJsonAccept
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
