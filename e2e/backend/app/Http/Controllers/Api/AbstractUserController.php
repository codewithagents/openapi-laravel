<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\UserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class AbstractUserController
{
    /**
     * POST /user
     *
     * Create user.
     */
    abstract public function createUser(UserData $user): UserData;

    /**
     * POST /user/createWithList
     *
     * Creates list of users with given input array.
     */
    abstract public function createUsersWithListInput(Request $request): UserData;

    /**
     * GET /user/login
     *
     * Logs user into the system.
     */
    abstract public function loginUser(): JsonResponse;

    /**
     * GET /user/logout
     *
     * Logs out current logged in user session.
     */
    abstract public function logoutUser(): JsonResponse;

    /**
     * GET /user/{username}
     *
     * Get user by user name.
     */
    abstract public function getUserByName(string $username): UserData;

    /**
     * PUT /user/{username}
     *
     * Update user resource.
     */
    abstract public function updateUser(UserData $user, string $username): JsonResponse;

    /**
     * DELETE /user/{username}
     *
     * Delete user resource.
     */
    abstract public function deleteUser(string $username): JsonResponse;
}
