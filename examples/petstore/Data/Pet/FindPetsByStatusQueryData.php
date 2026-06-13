<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * Query parameters of GET /pet/findByStatus.
 */
final class FindPetsByStatusQueryData extends Data
{
    public function __construct(
        public readonly string $status = 'available',
    ) {}

    /**
     * Validate against rules() and hydrate from the query string only, so
     * request-body fields never bleed into query validation (or vice versa).
     */
    public static function fromQuery(Request $request): self
    {
        return self::validateAndCreate($request->query->all());
    }

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['available', 'pending', 'sold'])],
        ];
    }
}
