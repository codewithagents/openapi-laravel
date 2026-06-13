<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lab team guard for the #77 security-matrix lab operations.
 *
 * The spec maps the lab_team apiKey scheme (header X-Lab-Team) to the
 * 'lab-team' middleware alias (config/openapi-laravel.php security.
 * middleware_map). The generator stamps this middleware onto every lab route
 * whose ENFORCED requirement object lists lab_team (the AND case combines it
 * with lab-session; the OR case never stamps it, since only the first
 * requirement object is enforced). This class passes only when the header
 * equals a fixed demo token, returning 401 otherwise.
 */
final class LabTeam
{
    private const TOKEN = 'team-1234';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Lab-Team');

        if (! is_string($provided) || ! hash_equals(self::TOKEN, $provided)) {
            return new JsonResponse(
                ['message' => 'Invalid or missing X-Lab-Team header.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }
}
