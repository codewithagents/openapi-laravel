<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class PetData extends Data
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
    ) {}

    /**
     * @return array<string, list<string|object>>
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
        ];
    }
}
