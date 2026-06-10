<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\ApiResponseData;
use App\Data\PetData;
use App\Data\PetWritableData;
use App\Support\PetStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hand-written concrete controller. It extends the GENERATED
 * AbstractPetController and implements every abstract method.
 *
 * This is where the cross-language serialization seams live. The generator
 * hands addPet/updatePet a PetWritableData (the write variant: it carries
 * secret_note, but not the readOnly created_at) and asks for a PetData back
 * (the read variant: it carries created_at, but never secret_note). The body
 * does the only thing the contract cannot do on its own: assign created_at
 * server-side and drop secret_note so it never reaches storage or any read.
 */
final class PetController extends AbstractPetController
{
    public function __construct(
        private readonly PetStore $store,
    ) {}

    public function addPet(PetWritableData $pet): PetData
    {
        // $pet arrived already hydrated and validated against the spec rules()
        // (a missing required name/photoUrls would have 422'd before we got
        // here). The CreatedResponse middleware on this route turns the 200 the
        // Data return would otherwise produce into a 201.
        //
        // created_at is readOnly, so it is absent from PetWritableData: we set
        // it server-side here. secret_note is writeOnly, so it is present on the
        // write variant but absent from PetData: copying field by field into a
        // PetData drops it by construction, so it never lands in storage or any
        // response.
        $created = $this->toReadModel($pet, createdAt: now()->toIso8601String());

        return $this->store->putPet($created);
    }

    public function updatePet(PetWritableData $pet): PetData
    {
        // Preserve the original created_at on update (the client cannot set it
        // because it is readOnly and not on the write variant). Fall back to a
        // fresh timestamp for an upsert of a previously-unseen id.
        $existing = $pet->id !== null ? $this->store->findPet($pet->id) : null;
        $createdAt = $existing?->createdAt ?? now()->toIso8601String();

        $updated = $this->toReadModel($pet, createdAt: $createdAt);

        return $this->store->putPet($updated);
    }

    public function findPetsByStatus(): DataCollection
    {
        $status = (string) request()->query('status', 'available');

        return PetData::collect($this->store->petsByStatus($status), DataCollection::class);
    }

    public function findPetsByTags(): DataCollection
    {
        $tags = request()->query('tags', []);
        $tags = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [(string) $tags];

        return PetData::collect($this->store->petsByTags($tags), DataCollection::class);
    }

    public function getPetById(int $petId): PetData
    {
        $pet = $this->store->findPet($petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        return $pet;
    }

    public function updatePetWithForm(int $petId): PetData
    {
        $pet = $this->store->findPet($petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        // Form fields arrive as query/body params (the spec models them inline),
        // so we patch the existing pet rather than rehydrate a whole PetData.
        // created_at and the enriched fields are carried over untouched.
        $name = request()->input('name');
        $status = request()->input('status');

        $updated = new PetData(
            name: is_string($name) ? $name : $pet->name,
            photoUrls: $pet->photoUrls,
            id: $pet->id,
            category: $pet->category,
            tags: $pet->tags,
            status: is_string($status) ? $status : $pet->status,
            microchipId: $pet->microchipId,
            createdAt: $pet->createdAt,
            weightKg: $pet->weightKg,
            attributes: $pet->attributes,
        );

        return $this->store->putPet($updated);
    }

    public function deletePet(int $petId): JsonResponse
    {
        $deleted = $this->store->deletePet($petId);

        if (! $deleted) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        return new JsonResponse(null, 204);
    }

    public function uploadFile(Request $request, int $petId): ApiResponseData
    {
        if ($this->store->findPet($petId) === null) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        $bytes = strlen($request->getContent());

        return new ApiResponseData(
            code: 200,
            type: 'success',
            message: "Image uploaded for pet {$petId} ({$bytes} bytes).",
        );
    }

    /**
     * Convert the write variant into the read model, assigning the server-side
     * created_at and dropping the writeOnly secret_note (it has no home on
     * PetData, so it simply is not copied).
     */
    private function toReadModel(PetWritableData $pet, string $createdAt): PetData
    {
        return new PetData(
            name: $pet->name,
            photoUrls: $pet->photoUrls,
            id: $pet->id,
            category: $pet->category,
            tags: $pet->tags,
            status: $pet->status,
            microchipId: $pet->microchipId,
            createdAt: $createdAt,
            weightKg: $pet->weightKg,
            attributes: $pet->attributes,
        );
    }
}
