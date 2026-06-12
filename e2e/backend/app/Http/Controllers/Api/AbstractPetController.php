<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Pet\ApiResponseData;
use App\Data\Pet\FindPetsByStatusQueryData;
use App\Data\Pet\FindPetsByTagsQueryData;
use App\Data\Pet\PetData;
use App\Data\Pet\PetWritableData;
use App\Data\Pet\UpdatePetWithFormQueryData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\DataCollection;

abstract class AbstractPetController
{
    /**
     * POST /pet
     *
     * Add a new pet to the store.
     */
    abstract public function addPet(PetWritableData $pet): PetData;

    /**
     * PUT /pet
     *
     * Update an existing pet.
     */
    abstract public function updatePet(PetWritableData $pet): PetData;

    /**
     * GET /pet/findByStatus
     *
     * Finds Pets by status.
     *
     * @return DataCollection<int, PetData>
     */
    abstract public function findPetsByStatus(FindPetsByStatusQueryData $query): DataCollection;

    /**
     * GET /pet/findByTags
     *
     * Finds Pets by tags.
     *
     * @return DataCollection<int, PetData>
     */
    abstract public function findPetsByTags(FindPetsByTagsQueryData $query): DataCollection;

    /**
     * GET /pet/{petId}
     *
     * Find pet by ID.
     */
    abstract public function show(int $petId): PetData;

    /**
     * POST /pet/{petId}
     *
     * Updates a pet in the store with form data.
     */
    abstract public function updatePetWithForm(UpdatePetWithFormQueryData $query, int $petId): PetData;

    /**
     * DELETE /pet/{petId}
     *
     * Deletes a pet.
     */
    abstract public function destroy(int $petId): JsonResponse;

    /**
     * POST /pet/{petId}/uploadImage
     *
     * Uploads an image.
     *
     * Query parameters: validate and hydrate them with
     * \App\Data\Pet\UploadFileQueryData::fromQuery($request).
     */
    abstract public function uploadFile(Request $request, int $petId): ApiResponseData;
}
