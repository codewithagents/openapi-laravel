<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * Query parameters of GET /user/login.
 */
final class LoginUserQueryData extends Data
{
    public function __construct(
        public readonly ?string $username = null,
        public readonly ?string $password = null,
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
            'username' => ['sometimes', 'string'],
            'password' => ['sometimes', 'string'],
        ];
    }
}
