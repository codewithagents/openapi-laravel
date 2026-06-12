<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Http\Controllers\Api;

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\PetData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Store\OrderData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\User\UserData;

/**
 * A trivial in-memory backing store for the demo controllers. This is NOT
 * generated and NOT part of the package: it stands in for the database/service
 * layer a real app would delegate to, so the demo stays self-contained and the
 * feature tests can exercise the full generated request/response chain.
 *
 * Registered as a singleton in the test app so all three controllers share one
 * set of pets, orders, and users for the duration of a request lifecycle.
 *
 * @phpstan-type PetList array<int, PetData>
 * @phpstan-type OrderList array<int, OrderData>
 * @phpstan-type UserList array<string, UserData>
 */
final class InMemoryStore
{
    /**
     * @var array<int, PetData>
     */
    private array $pets = [];

    /**
     * @var array<int, OrderData>
     */
    private array $orders = [];

    /**
     * @var array<string, UserData>
     */
    private array $users = [];

    private int $nextPetId = 1;

    public function putPet(PetData $pet): PetData
    {
        $id = $pet->id ?? $this->nextPetId++;

        $stored = new PetData(
            name: $pet->name,
            photoUrls: $pet->photoUrls,
            id: $id,
            category: $pet->category,
            tags: $pet->tags,
            status: $pet->status,
        );

        $this->pets[$id] = $stored;

        return $stored;
    }

    public function findPet(int $id): ?PetData
    {
        return $this->pets[$id] ?? null;
    }

    /**
     * @return list<PetData>
     */
    public function petsByStatus(string $status): array
    {
        return array_values(array_filter(
            $this->pets,
            static fn (PetData $pet): bool => $pet->status === $status,
        ));
    }

    /**
     * @param  list<string>  $tags
     * @return list<PetData>
     */
    public function petsByTags(array $tags): array
    {
        if ($tags === []) {
            return array_values($this->pets);
        }

        return array_values(array_filter($this->pets, static function (PetData $pet) use ($tags): bool {
            foreach ($pet->tags ?? [] as $tag) {
                if (in_array($tag->name, $tags, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function deletePet(int $id): bool
    {
        if (! isset($this->pets[$id])) {
            return false;
        }

        unset($this->pets[$id]);

        return true;
    }

    public function putOrder(OrderData $order): OrderData
    {
        $id = $order->id ?? count($this->orders) + 1;
        $stored = new OrderData(
            id: $id,
            petId: $order->petId,
            quantity: $order->quantity,
            shipDate: $order->shipDate,
            status: $order->status ?? 'placed',
            complete: $order->complete ?? false,
        );

        $this->orders[$id] = $stored;

        return $stored;
    }

    public function findOrder(int $id): ?OrderData
    {
        return $this->orders[$id] ?? null;
    }

    public function deleteOrder(int $id): bool
    {
        if (! isset($this->orders[$id])) {
            return false;
        }

        unset($this->orders[$id]);

        return true;
    }

    public function putUser(UserData $user): UserData
    {
        $username = $user->username ?? 'anonymous';
        $this->users[$username] = $user;

        return $user;
    }

    public function findUser(string $username): ?UserData
    {
        return $this->users[$username] ?? null;
    }

    public function deleteUser(string $username): bool
    {
        if (! isset($this->users[$username])) {
            return false;
        }

        unset($this->users[$username]);

        return true;
    }

    /**
     * Status code => count, for the store inventory endpoint.
     *
     * @return array<string, int>
     */
    public function inventory(): array
    {
        $counts = [];
        foreach ($this->pets as $pet) {
            $status = $pet->status ?? 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }
}
