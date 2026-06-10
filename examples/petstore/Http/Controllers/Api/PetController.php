<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Http\Controllers\Api;

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\ApiResponseData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\PetData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\DataCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hand-written concrete controller. It extends the GENERATED
 * AbstractPetController and implements every abstract method against the
 * in-memory store. This is the only non-generated PHP in the Pet flow: the
 * typed PetData parameter, its spec-derived validation, and the typed return
 * value all come from the generator. The body just wires intent to storage.
 */
final class PetController extends AbstractPetController
{
    public function __construct(
        private readonly InMemoryStore $store,
    ) {}

    public function addPet(PetData $pet): PetData
    {
        // $pet arrived already hydrated and validated against the spec rules()
        // (a missing required name/photoUrls would have 422'd before we got here).
        return $this->store->putPet($pet);
    }

    public function updatePet(PetData $pet): PetData
    {
        return $this->store->putPet($pet);
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
        $name = request()->input('name');
        $status = request()->input('status');

        $updated = new PetData(
            name: is_string($name) ? $name : $pet->name,
            photoUrls: $pet->photoUrls,
            id: $pet->id,
            category: $pet->category,
            tags: $pet->tags,
            status: is_string($status) ? $status : $pet->status,
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
}
