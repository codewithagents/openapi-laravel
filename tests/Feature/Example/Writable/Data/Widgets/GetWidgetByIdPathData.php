<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * Path parameters of GET /widgets/{widgetId}.
 */
final class GetWidgetByIdPathData extends Data
{
    public function __construct(
        public readonly int $widgetId,
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
            'widgetId' => ['required', 'integer'],
        ];
    }
}
