<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-key gate for the upload operation (issue #77).
 *
 * The spec marks POST /pet/{petId}/uploadImage as requiring the pet_upload_key
 * apiKey scheme (header X-API-Key). config/openapi-laravel.php maps that scheme
 * to the 'api-key' middleware alias, so the generator stamps this middleware
 * onto the generated uploadFile route. The generator decides WHICH routes are
 * protected (from the spec); this class is the consumer-written enforcement: it
 * compares the header against a fixed demo key and returns 401 when it does not
 * match. The demo key is read from env so tests and the SPA can agree on it.
 */
final class ApiKey
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('PET_UPLOAD_API_KEY', 'demo-upload-key');
        $provided = $request->header('X-API-Key');

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return new JsonResponse(
                ['message' => 'Invalid or missing API key.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $next($request);
    }
}
