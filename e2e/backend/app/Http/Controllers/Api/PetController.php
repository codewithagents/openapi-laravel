<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Pet\ApiResponseData;
use App\Data\Pet\FindPetsByStatusQueryData;
use App\Data\Pet\FindPetsByTagsQueryData;
use App\Data\Pet\PetData;
use App\Data\Pet\PetWritableData;
use App\Data\Pet\UpdatePetWithFormQueryData;
use App\Data\Pet\UploadFileRequestData;
use App\Support\PetStore;
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

    public function findPetsByStatus(FindPetsByStatusQueryData $query): DataCollection
    {
        // $query arrived typed, validated against the spec rules(), and
        // hydrated from the query string only: the spec default ('available')
        // filled in when the parameter was omitted.
        return PetData::collect($this->store->petsByStatus($query->status), DataCollection::class);
    }

    public function findPetsByTags(FindPetsByTagsQueryData $query): DataCollection
    {
        return PetData::collect($this->store->petsByTags($query->tags), DataCollection::class);
    }

    public function show(int $petId): PetData
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
        // created_at and the enriched fields are carried over untouched.
        $updated = new PetData(
            name: $query->name ?? $pet->name,
            photoUrls: $pet->photoUrls,
            id: $pet->id,
            category: $pet->category,
            tags: $pet->tags,
            status: $query->status ?? $pet->status,
            microchipId: $pet->microchipId,
            createdAt: $pet->createdAt,
            weightKg: $pet->weightKg,
            attributes: $pet->attributes,
            externalId: $pet->externalId,
        );

        return $this->store->putPet($updated);
    }

    public function destroy(int $petId): void
    {
        // The generated abstract types this void: the spec declares 204 for the
        // delete (issue #64), so the route carries RespondsWithStatus:204, which
        // turns the framework's default 200 (empty body) into a 204 No Content.
        // The handler only signals success or a 404; it never sets a status.
        $deleted = $this->store->deletePet($petId);

        if (! $deleted) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }
    }

    public function uploadFile(UploadFileRequestData $body, int $petId): ApiResponseData
    {
        // $body arrived fully hydrated and validated against the generated
        // multipart rules() (issue #75): $body->image is a real UploadedFile
        // that already passed the 'file' + 'mimetypes:image/png' rules, so a
        // non-PNG or missing part would have 422'd before reaching this method.
        $pet = $this->store->findPet($petId);

        if ($pet === null) {
            throw new NotFoundHttpException("Pet {$petId} not found.");
        }

        // Persist the upload on the PUBLIC disk (storage/app/public/uploads) and
        // record it on the pet by appending the served URL to photoUrls. The
        // public disk is exposed at /storage via `php artisan storage:link`
        // (public/storage -> storage/app/public), run at container start, so the
        // recorded /storage/uploads/<file> URL actually serves the image bytes.
        $filename = "pet-{$petId}-".uniqid().'.png';
        $body->image->storeAs('uploads', $filename, 'public');

        $photoUrl = "/storage/uploads/{$filename}";
        $this->store->putPet($this->withPhoto($pet, $photoUrl));

        $size = $body->image->getSize() ?? 0;
        $caption = $body->caption !== null ? " (caption: {$body->caption})" : '';

        return new ApiResponseData(
            code: 200,
            type: 'success',
            message: "Image uploaded for pet {$petId}: {$photoUrl} ({$size} bytes){$caption}.",
        );
    }

    /**
     * Return a copy of the pet with one photo URL appended. Used by the upload
     * to record the stored image on the pet without touching its other fields.
     */
    private function withPhoto(PetData $pet, string $photoUrl): PetData
    {
        return new PetData(
            name: $pet->name,
            photoUrls: [...$pet->photoUrls, $photoUrl],
            id: $pet->id,
            category: $pet->category,
            tags: $pet->tags,
            status: $pet->status,
            microchipId: $pet->microchipId,
            createdAt: $pet->createdAt,
            weightKg: $pet->weightKg,
            attributes: $pet->attributes,
            externalId: $pet->externalId,
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
            // Preserve external_id exactly as it arrived: a string stays a
            // string, an integer stays an integer (the oneOf union type).
            externalId: $pet->externalId,
        );
    }
}
