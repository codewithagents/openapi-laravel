<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * Header parameters of DELETE /pet/{petId}.
 */
final class DeletePetHeaderData extends Data
{
    public function __construct(
        #[MapName('api_key')]
        public readonly ?string $apiKey = null,
    ) {}

    /**
     * Validate against rules() and hydrate from the request headers only, so
     * a constrained custom header is enforced at runtime (a bad value is a 422,
     * not a silent 200). HTTP header names are case-insensitive (read
     * lowercased) and each value is an array, so the first value of each
     * declared header is taken before validation.
     */
    public static function fromHeaders(Request $request): static
    {
        $all = $request->headers->all();
        $headers = [];

        foreach (['api_key'] as $name) {
            if (isset($all[$name]) && $all[$name] !== []) {
                $headers[$name] = $all[$name][0];
            }
        }

        return self::validateAndCreate($headers);
    }

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'api_key' => ['sometimes', 'string'],
        ];
    }
}
