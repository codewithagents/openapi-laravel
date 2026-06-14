<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Store;

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Support\Rfc3339DateTimeRule;
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
            'id' => ['sometimes', 'integer', 'min:-9223372036854775808', 'max:9223372036854775807'],
            'petId' => ['sometimes', 'integer', 'min:-9223372036854775808', 'max:9223372036854775807'],
            'quantity' => ['sometimes', 'integer', 'min:-2147483648', 'max:2147483647'],
            'shipDate' => ['sometimes', 'string', new Rfc3339DateTimeRule],
            'status' => ['sometimes', Rule::in(['placed', 'approved', 'delivered'])],
            'complete' => ['sometimes', 'boolean'],
        ];
    }
}
