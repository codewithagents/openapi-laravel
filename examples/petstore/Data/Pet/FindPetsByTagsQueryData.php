<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Pet;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * Query parameters of GET /pet/findByTags.
 */
final class FindPetsByTagsQueryData extends Data
{
    public function __construct(
        /** @var array<int, string> */
        public readonly array $tags,
    ) {}

    /**
     * Validate against rules() and hydrate from the query string only, so
     * request-body fields never bleed into query validation (or vice versa).
     */
    public static function fromQuery(Request $request): static
    {
        return self::validateAndCreate($request->query->all());
    }

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'tags' => ['required', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
