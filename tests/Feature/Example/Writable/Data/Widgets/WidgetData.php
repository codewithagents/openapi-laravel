<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets;

use Spatie\LaravelData\Data;

final class WidgetData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $id = null,
    ) {}

    /**
     * @return array<array-key, list<string|object>>
     */
    public static function rules(): array
    {
        return [
            'id' => ['sometimes', 'integer'],
            'name' => ['required', 'string'],
        ];
    }
}
