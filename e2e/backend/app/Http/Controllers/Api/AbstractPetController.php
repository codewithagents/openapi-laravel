<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\ApiResponseData;
use App\Data\PetData;
use App\Data\PetWritableData;
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
    abstract public function findPetsByStatus(): DataCollection;

    /**
     * GET /pet/findByTags
     *
     * Finds Pets by tags.
     *
     * @return DataCollection<int, PetData>
     */
    abstract public function findPetsByTags(): DataCollection;

    /**
     * GET /pet/{petId}
     *
     * Find pet by ID.
     */
    abstract public function getPetById(int $petId): PetData;

    /**
     * POST /pet/{petId}
     *
     * Updates a pet in the store with form data.
     */
    abstract public function updatePetWithForm(int $petId): PetData;

    /**
     * DELETE /pet/{petId}
     *
     * Deletes a pet.
     */
    abstract public function deletePet(int $petId): JsonResponse;

    /**
     * POST /pet/{petId}/uploadImage
     *
     * Uploads an image.
     */
    abstract public function uploadFile(Request $request, int $petId): ApiResponseData;
}
