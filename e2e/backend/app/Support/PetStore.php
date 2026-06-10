<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\OrderData;
use App\Data\PetData;
use App\Data\TagData;
use App\Data\UserData;

/**
 * A trivial in-memory backing store for the demo controllers. This is NOT
 * generated and NOT part of the openapi-laravel package: it stands in for the
 * database/service layer a real app would delegate to, so the contract-first
 * demo stays self-contained and bootable without a database.
 *
 * Registered as a singleton (see AppServiceProvider) so every controller shares
 * one set of pets, orders, and users for the lifetime of the process.
 *
 * @phpstan-type PetList array<int, PetData>
 * @phpstan-type OrderList array<int, OrderData>
 * @phpstan-type UserList array<string, UserData>
 */
final class PetStore
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

    public function __construct()
    {
        $this->seed();
    }

    /**
     * Seed deterministic demo data. Under `php artisan serve` (PHP's built-in
     * server) each HTTP request is a fresh process, so an empty store would
     * reset between requests and the GET/DELETE proofs would have nothing to
     * read. Seeding at construction gives every request the same starting set,
     * so the demo is bootable and verifiable without a database. A later
     * milestone backing this with a real store can drop the seed.
     */
    private function seed(): void
    {
        $this->putPet(new PetData(
            name: 'Rex',
            photoUrls: ['https://example.com/rex.png'],
            id: 1,
            tags: [new TagData(id: 1, name: 'good-boy')],
            status: 'available',
        ));

        $this->putPet(new PetData(
            name: 'Whiskers',
            photoUrls: ['https://example.com/whiskers.png'],
            id: 2,
            status: 'pending',
        ));
    }

    /**
     * Create or replace a pet. A missing id is auto-incremented, so a POST with
     * no id comes back with one assigned.
     */
    public function putPet(PetData $pet): PetData
    {
        $id = $pet->id ?? $this->nextPetId++;

        // Keep nextPetId ahead of any explicitly supplied id so later
        // auto-increments never collide with an existing entry.
        if ($id >= $this->nextPetId) {
            $this->nextPetId = $id + 1;
        }

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
    public function allPets(): array
    {
        return array_values($this->pets);
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
