<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\OrderData;
use App\Data\PetData;
use App\Data\TagData;
use App\Data\UserData;

/**
 * A file-backed JSON store for the demo controllers. This is NOT generated and
 * NOT part of the openapi-laravel package: it stands in for the database/service
 * layer a real app would delegate to, so the contract-first demo stays
 * self-contained and bootable without a database.
 *
 * Why a file and not an in-memory singleton: `php artisan serve` (PHP's built-in
 * server) and the later Docker/php-fpm setup each handle requests in separate
 * workers/processes, so an in-process array resets between requests and a
 * create in one request would be invisible to a list in the next. A JSON file
 * under storage/app survives across requests and processes. Every mutating
 * call is a read-modify-write of that file.
 *
 * Pets are persisted through the generated PetData read-model: toArray() on the
 * way to disk (so created_at and the snake_case wire names are what land in the
 * file) and PetData::from() on the way back. secret_note never reaches here
 * because it only exists on PetWritableData; the controller drops it before any
 * PetData is built.
 *
 * @phpstan-type StateArray array{pets: array<int, array<string, mixed>>, orders: array<int, array<string, mixed>>, users: array<string, array<string, mixed>>, nextPetId: int}
 */
final class PetStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('app/petstore.json');
    }

    /**
     * Replace the entire store with the deterministic seed set. Used by the
     * demo to get a known starting state, and safe to call from a test reset.
     */
    public function reset(): void
    {
        $this->save($this->emptyState());

        $this->putPet(new PetData(
            name: 'Rex',
            photoUrls: ['https://example.com/rex.png'],
            id: 1,
            tags: [new TagData(id: 1, name: 'good-boy')],
            status: 'available',
            microchipId: 'chip-rex-0001',
            createdAt: '2026-01-01T00:00:00+00:00',
            weightKg: 12.5,
            attributes: ['color' => 'brown', 'breed' => 'labrador'],
            externalId: 'ext-rex-legacy',
        ));

        $this->putPet(new PetData(
            name: 'Whiskers',
            photoUrls: ['https://example.com/whiskers.png'],
            id: 2,
            status: 'pending',
            createdAt: '2026-01-02T00:00:00+00:00',
            weightKg: null,
            attributes: [],
            externalId: 559900,
        ));
    }

    /**
     * Create or replace a pet. A missing id is auto-incremented, so a POST with
     * no id comes back with one assigned. Persisted to the JSON file.
     */
    public function putPet(PetData $pet): PetData
    {
        $state = $this->load();

        $id = $pet->id ?? $state['nextPetId'];
        if ($id >= $state['nextPetId']) {
            $state['nextPetId'] = $id + 1;
        }

        $stored = new PetData(
            name: $pet->name,
            photoUrls: $pet->photoUrls,
            id: $id,
            category: $pet->category,
            tags: $pet->tags,
            status: $pet->status,
            microchipId: $pet->microchipId,
            createdAt: $pet->createdAt,
            weightKg: $pet->weightKg,
            attributes: $pet->attributes,
            externalId: $pet->externalId,
        );

        $state['pets'][$id] = $stored->toArray();
        $this->save($state);

        return $stored;
    }

    public function findPet(int $id): ?PetData
    {
        $state = $this->load();
        $row = $state['pets'][$id] ?? null;

        return is_array($row) ? PetData::from($row) : null;
    }

    /**
     * @return list<PetData>
     */
    public function allPets(): array
    {
        $state = $this->load();

        return array_values(array_map(
            static fn (array $row): PetData => PetData::from($row),
            $state['pets'],
        ));
    }

    /**
     * @return list<PetData>
     */
    public function petsByStatus(string $status): array
    {
        return array_values(array_filter(
            $this->allPets(),
            static fn (PetData $pet): bool => $pet->status === $status,
        ));
    }

    /**
     * @param  list<string>  $tags
     * @return list<PetData>
     */
    public function petsByTags(array $tags): array
    {
        $pets = $this->allPets();

        if ($tags === []) {
            return $pets;
        }

        return array_values(array_filter($pets, static function (PetData $pet) use ($tags): bool {
            foreach ($pet->tags ?? [] as $tag) {
                $name = is_array($tag) ? ($tag['name'] ?? null) : ($tag->name ?? null);
                if (is_string($name) && in_array($name, $tags, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function deletePet(int $id): bool
    {
        $state = $this->load();

        if (! isset($state['pets'][$id])) {
            return false;
        }

        unset($state['pets'][$id]);
        $this->save($state);

        return true;
    }

    public function putOrder(OrderData $order): OrderData
    {
        $state = $this->load();

        $id = $order->id ?? count($state['orders']) + 1;
        $stored = new OrderData(
            id: $id,
            petId: $order->petId,
            quantity: $order->quantity,
            shipDate: $order->shipDate,
            status: $order->status ?? 'placed',
            complete: $order->complete ?? false,
        );

        $state['orders'][$id] = $stored->toArray();
        $this->save($state);

        return $stored;
    }

    public function findOrder(int $id): ?OrderData
    {
        $state = $this->load();
        $row = $state['orders'][$id] ?? null;

        return is_array($row) ? OrderData::from($row) : null;
    }

    public function deleteOrder(int $id): bool
    {
        $state = $this->load();

        if (! isset($state['orders'][$id])) {
            return false;
        }

        unset($state['orders'][$id]);
        $this->save($state);

        return true;
    }

    public function putUser(UserData $user): UserData
    {
        $state = $this->load();

        $username = $user->username ?? 'anonymous';
        $state['users'][$username] = $user->toArray();
        $this->save($state);

        return $user;
    }

    public function findUser(string $username): ?UserData
    {
        $state = $this->load();
        $row = $state['users'][$username] ?? null;

        return is_array($row) ? UserData::from($row) : null;
    }

    public function deleteUser(string $username): bool
    {
        $state = $this->load();

        if (! isset($state['users'][$username])) {
            return false;
        }

        unset($state['users'][$username]);
        $this->save($state);

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
        foreach ($this->allPets() as $pet) {
            $status = $pet->status ?? 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Read the persisted state, seeding the file on first use so reads/deletes
     * always have deterministic data without a manual reset step.
     *
     * @return StateArray
     */
    private function load(): array
    {
        if (! is_file($this->path)) {
            $this->reset();
        }

        $raw = @file_get_contents($this->path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return $this->emptyState();
        }

        /** @var StateArray $state */
        $state = [
            'pets' => is_array($decoded['pets'] ?? null) ? $decoded['pets'] : [],
            'orders' => is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [],
            'users' => is_array($decoded['users'] ?? null) ? $decoded['users'] : [],
            'nextPetId' => is_int($decoded['nextPetId'] ?? null) ? $decoded['nextPetId'] : 1,
        ];

        return $state;
    }

    /**
     * @param  StateArray  $state
     */
    private function save(array $state): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Lock so two concurrent php-fpm/serve workers cannot interleave a
        // read-modify-write and lose an entry.
        file_put_contents(
            $this->path,
            (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    /**
     * @return StateArray
     */
    private function emptyState(): array
    {
        return ['pets' => [], 'orders' => [], 'users' => [], 'nextPetId' => 1];
    }
}
