<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet;

use Spatie\LaravelData\Data;

final class ApiResponseData extends Data
{
    public function __construct(
        public readonly ?int $code = null,
        public readonly ?string $type = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'code' => ['sometimes', 'integer'],
            'type' => ['sometimes', 'string'],
            'message' => ['sometimes', 'string'],
        ];
    }
}
