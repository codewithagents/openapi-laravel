<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\User\LoginUserQueryData;
use App\Data\User\UserData;
use App\Support\PetStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hand-written concrete controller extending the GENERATED
 * AbstractUserController. Implements the user flow against the in-memory store.
 */
final class UserController extends AbstractUserController
{
    public function __construct(
        private readonly PetStore $store,
    ) {}

    public function createUser(UserData $user): UserData
    {
        return $this->store->putUser($user);
    }

    public function createUsersWithListInput(Request $request): UserData
    {
        // The spec body is an inline array of users, so the generator injects a
        // raw Request rather than a typed param. We hydrate the list by hand and
        // return the first created user, matching the spec's response shape.
        $payload = $request->json()->all();
        $rows = is_array($payload) ? $payload : [];

        $last = null;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $last = $this->store->putUser(UserData::from($row));
            }
        }

        return $last ?? new UserData;
    }

    public function loginUser(LoginUserQueryData $query): JsonResponse
    {
        return new JsonResponse('logged in');
    }

    public function logoutUser(): JsonResponse
    {
        return new JsonResponse('logged out');
    }

    public function show(string $username): UserData
    {
        $user = $this->store->findUser($username);

        if ($user === null) {
            throw new NotFoundHttpException("User {$username} not found.");
        }

        return $user;
    }

    public function update(UserData $user, string $username): JsonResponse
    {
        if ($this->store->findUser($username) === null) {
            throw new NotFoundHttpException("User {$username} not found.");
        }

        $this->store->putUser($user);

        return new JsonResponse(null, 204);
    }

    public function destroy(string $username): JsonResponse
    {
        $deleted = $this->store->deleteUser($username);

        if (! $deleted) {
            throw new NotFoundHttpException("User {$username} not found.");
        }

        return new JsonResponse(null, 204);
    }
}
