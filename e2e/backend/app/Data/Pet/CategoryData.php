<?php

declare(strict_types=1);

namespace App\Data\Pet;

use Spatie\LaravelData\Data;

final class CategoryData extends Data
{
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $name = null,
    ) {}

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'id' => ['sometimes', 'integer'],
            'name' => ['sometimes', 'string'],
        ];
    }
}
