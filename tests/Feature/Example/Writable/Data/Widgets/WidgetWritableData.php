<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets;

use Spatie\LaravelData\Data;

final class WidgetWritableData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $secret,
    ) {}

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'secret' => ['required', 'string'],
        ];
    }
}
