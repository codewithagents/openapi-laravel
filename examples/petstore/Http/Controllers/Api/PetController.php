<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Http\Controllers\Api;

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\ApiResponseData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\FindPetsByStatusQueryData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\FindPetsByTagsQueryData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\PetData;
use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\UpdatePetWithFormQueryData;
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

    public function findPetsByStatus(FindPetsByStatusQueryData $query): DataCollection
    {
        // $query arrived typed, validated against the spec rules(), and
        // hydrated from the query string only (issue #63): an out-of-enum
        // status 422'd before this method ran, and the spec default
        // ('available') filled in when the parameter was omitted.
        return PetData::collect($this->store->petsByStatus($query->status), DataCollection::class);
    }

    public function findPetsByTags(FindPetsByTagsQueryData $query): DataCollection
    {
        return PetData::collect($this->store->petsByTags($query->tags), DataCollection::class);
    }

    public function getPetById(int $petId): PetData
    {
        $pet = $this->store->findPet($petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        return $pet;
    }

    public function updatePetWithForm(UpdatePetWithFormQueryData $query, int $petId): PetData
    {
        $pet = $this->store->findPet($petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        // The spec models name/status as query parameters; $query carries them
        // typed and validated, so we patch the existing pet from it.
        $updated = new PetData(
            name: $query->name ?? $pet->name,
            photoUrls: $pet->photoUrls,
            id: $pet->id,
            category: $pet->category,
            tags: $pet->tags,
            status: $query->status ?? $pet->status,
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
