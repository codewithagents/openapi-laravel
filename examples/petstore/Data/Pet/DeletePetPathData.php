<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * Path parameters of DELETE /pet/{petId}.
 */
final class DeletePetPathData extends Data
{
    public function __construct(
        public readonly int $petId,
    ) {}

    /**
     * Validate against rules() and hydrate from the resolved route parameters
     * only, so path-segment constraints are enforced at runtime (a bad value
     * is a 422, not a silent 200).
     */
    public static function fromRoute(Request $request): self
    {
        return self::validateAndCreate($request->route()->parameters());
    }

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'petId' => ['required', 'integer', 'min:-9223372036854775808', 'max:9223372036854775807'],
        ];
    }
}
