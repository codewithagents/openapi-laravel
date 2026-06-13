<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the X-Total-Count response header on the findPetsByStatus list response.
 *
 * This exercises a DOCUMENTED RESIDUAL, not a generator feature. The spec
 * declares an X-Total-Count response header on GET /pet/findByStatus, but the
 * generator does NOT generate response-header handling (issue #114): it only
 * WARNS about the declared header and leaves it to the consumer. So the header
 * here is entirely hand-written glue: the generator's contribution is the
 * warning, and this middleware is what a real adopter would write to honor the
 * documented header. The Playwright assertion therefore proves consumer glue
 * works, NOT that the generator emits header handling.
 *
 * Scoped by route name so it only touches the one list operation that declares
 * the header. It counts the items in the JSON array body the controller
 * returned and stamps the count onto the response.
 */
final class TotalCountHeader
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->route()?->getName() !== 'findPetsByStatus') {
            return $response;
        }

        $decoded = json_decode((string) $response->getContent(), true);

        if (is_array($decoded)) {
            $response->headers->set('X-Total-Count', (string) count($decoded));
        }

        return $response;
    }
}
