<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marker middleware for the generated route group (issue #71).
 *
 * config/openapi-laravel.php sets routes.middleware = ['route-group-marker']
 * and routes.prefix = 'v1', so the generator wraps every generated route in one
 * Route::middleware(['route-group-marker'])->prefix('v1')->group(...). This
 * middleware stamps an X-Route-Group header on the response so the e2e suite can
 * prove the group middleware actually runs over real HTTP. The prefix is proven
 * by the routes only being reachable under /api/v1.
 *
 * This is the consumer side of #71: the generator wires the group from config;
 * the adopter supplies the middleware behind the alias.
 */
final class RouteGroupMarker
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Route-Group', 'v1');

        return $response;
    }
}
