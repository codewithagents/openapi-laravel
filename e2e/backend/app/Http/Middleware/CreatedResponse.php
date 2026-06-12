<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Promotes a successful 200 response to 201 Created for creation operations.
 *
 * The generated AbstractPetController types addPet() as returning a PetData,
 * and a spatie/laravel-data object serialises with a 200 status. The OpenAPI
 * contract documents resource creation as 201, so this thin middleware bridges
 * the gap without touching the generated type contract: the controller still
 * returns the typed PetData, the middleware just rewrites the status on the way
 * out. It only acts on the create operations (matched by the generated
 * controller action method), so reads and updates keep their 200.
 */
final class CreatedResponse
{
    /**
     * Generated controller action methods that create a new resource.
     *
     * @var list<string>
     */
    private const CREATE_ACTIONS = ['addPet', 'store', 'createUser'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $action = $request->route()?->getActionMethod();

        if ($response->getStatusCode() === Response::HTTP_OK
            && is_string($action)
            && in_array($action, self::CREATE_ACTIONS, true)) {
            $response->setStatusCode(Response::HTTP_CREATED);
        }

        return $response;
    }
}
