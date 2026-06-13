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

abstract class AbstractPetController
{
    /**
     * POST /pet
     *
     * Add a new pet to the store.
     */
    abstract public function addPet(PetData $pet): PetData;

    /**
     * PUT /pet
     *
     * Update an existing pet.
     */
    abstract public function updatePet(PetData $pet): PetData;

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
     *
     * Path parameters: validate them with
     * \CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\GetPetByIdPathData::fromRoute($request).
     */
    abstract public function show(int $petId): PetData;

    /**
     * POST /pet/{petId}
     *
     * Updates a pet in the store with form data.
     *
     * Path parameters: validate them with
     * \CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\UpdatePetWithFormPathData::fromRoute($request).
     */
    abstract public function updatePetWithForm(UpdatePetWithFormQueryData $query, int $petId): PetData;

    /**
     * DELETE /pet/{petId}
     *
     * Deletes a pet.
     *
     * Path parameters: validate them with
     * \CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\DeletePetPathData::fromRoute($request).
     *
     * Header parameters: validate them with
     * \CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\DeletePetHeaderData::fromHeaders($request).
     */
    abstract public function destroy(int $petId): JsonResponse;

    /**
     * POST /pet/{petId}/uploadImage
     *
     * Uploads an image.
     *
     * Query parameters: validate and hydrate them with
     * \CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\UploadFileQueryData::fromQuery($request).
     *
     * Path parameters: validate them with
     * \CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet\UploadFilePathData::fromRoute($request).
     */
    abstract public function uploadFile(Request $request, int $petId): ApiResponseData;
}
