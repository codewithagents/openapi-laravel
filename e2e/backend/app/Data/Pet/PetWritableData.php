<?php

declare(strict_types=1);

namespace App\Data\Pet;

use App\Data\Support\MapObjectTransformer;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Data;

final class PetWritableData extends Data
{
    public function __construct(
        public readonly string $name,
        /** @var array<int, string> */
        public readonly array $photoUrls,
        public readonly ?int $id = null,
        public readonly ?CategoryData $category = null,
        /** @var array<int, TagData> */
        #[DataCollectionOf(TagData::class)]
        public readonly ?array $tags = null,
        public readonly ?string $status = null,
        #[MapName('microchip_id')]
        public readonly ?string $microchipId = null,
        #[MapName('secret_note')]
        public readonly ?string $secretNote = null,
        #[MapName('weight_kg')]
        public readonly ?float $weightKg = null,
        /** @var array<string, string> */
        #[WithTransformer(MapObjectTransformer::class)]
        public readonly ?array $attributes = null,
        /** @var string|int */
        #[MapName('external_id')]
        public readonly string|int|null $externalId = null,
    ) {}

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'id' => ['sometimes', 'integer'],
            'name' => ['required', 'string'],
            'category' => ['sometimes'],
            'photoUrls' => ['required', 'array'],
            'photoUrls.*' => ['string'],
            'tags' => ['sometimes', 'array'],
            'status' => ['sometimes', Rule::in(['available', 'pending', 'sold'])],
            'microchip_id' => ['sometimes', 'string'],
            'secret_note' => ['sometimes', 'string'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric'],
            'attributes' => ['sometimes', 'array'],
            'attributes.*' => ['string'],
            'external_id' => ['sometimes'],
        ];
    }
}
