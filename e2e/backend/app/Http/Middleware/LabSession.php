<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lab session guard for the #77 security-matrix lab operations.
 *
 * The spec maps the lab_session apiKey scheme (header X-Lab-Session) to the
 * 'lab-session' middleware alias (config/openapi-laravel.php security.
 * middleware_map). The generator stamps this middleware onto every lab route
 * whose ENFORCED requirement object lists lab_session. This class is the
 * consumer-written enforcement: it passes only when the header equals a fixed
 * demo token, returning 401 otherwise.
 */
final class LabSession
{
    private const TOKEN = 'sess-1234';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Lab-Session');

        if (! is_string($provided) || ! hash_equals(self::TOKEN, $provided)) {
            return new JsonResponse(
                ['message' => 'Invalid or missing X-Lab-Session header.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }
}
