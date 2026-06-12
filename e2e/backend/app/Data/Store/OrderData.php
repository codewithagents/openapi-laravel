<?php

declare(strict_types=1);

namespace App\Data\Store;

use App\Data\Support\Rfc3339DateTimeRule;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

final class OrderData extends Data
{
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $petId = null,
        public readonly ?int $quantity = null,
        public readonly ?string $shipDate = null,
        public readonly ?string $status = null,
        public readonly ?bool $complete = null,
    ) {}

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'id' => ['sometimes', 'integer'],
            'petId' => ['sometimes', 'integer'],
            'quantity' => ['sometimes', 'integer'],
            'shipDate' => ['sometimes', 'string', new Rfc3339DateTimeRule],
            'status' => ['sometimes', Rule::in(['placed', 'approved', 'delivered'])],
            'complete' => ['sometimes', 'boolean'],
        ];
    }
}
