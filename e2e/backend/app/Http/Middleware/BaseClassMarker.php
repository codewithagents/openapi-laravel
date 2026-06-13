<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marker middleware for the configurable controller base class (issue #83).
 *
 * config/openapi-laravel.php sets controllers.base_class = BaseApiController, so
 * the generator emits `abstract class Abstract*Controller extends
 * BaseApiController`. The base declares this middleware via the Laravel 12
 * HasMiddleware interface, so it runs on every route handled by a generated
 * controller. It stamps an X-Base-Class header so the e2e suite can prove, over
 * real HTTP, that the generated abstract truly extends the configured base and
 * the inheritance is live.
 *
 * This is the consumer side of controllers.base_class: the generator wires the
 * extends clause from config; the adopter supplies the base class and the
 * marker behind it.
 */
final class BaseClassMarker
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Base-Class', 'openapi-laravel');

        return $response;
    }
}
