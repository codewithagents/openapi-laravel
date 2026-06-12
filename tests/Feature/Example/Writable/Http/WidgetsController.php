<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Http;

use CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets\WidgetData;
use CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets\WidgetWritableData;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hand-written concrete controller extending the GENERATED
 * AbstractWidgetsController. It proves the read/write-split flow end to end:
 * the createWidget body param is the writable variant (WidgetWritableData,
 * which carries the writeOnly secret and drops the readOnly id), while the
 * return value is the read variant (WidgetData, which carries the readOnly id
 * and drops the writeOnly secret). A trivial in-memory store stands in for a
 * database, mapping a writable input to a stored read shape.
 */
final class WidgetsController extends AbstractWidgetsController
{
    /**
     * @var array<int, WidgetData>
     */
    private static array $widgets = [];

    private static int $nextId = 1;

    public static function reset(): void
    {
        self::$widgets = [];
        self::$nextId = 1;
    }

    public function createWidget(WidgetWritableData $widget): WidgetData
    {
        // $widget arrived hydrated and validated against WidgetWritableData::rules()
        // (a missing required name or secret would have 422'd before reaching here).
        // The store assigns the readOnly id and drops the writeOnly secret, so the
        // read variant is what flows back out.
        $id = self::$nextId++;
        $stored = new WidgetData(name: $widget->name, id: $id);
        self::$widgets[$id] = $stored;

        return $stored;
    }

    public function getWidgetById(int $widgetId): WidgetData
    {
        $widget = self::$widgets[$widgetId] ?? null;

        if ($widget === null) {
            throw new NotFoundHttpException("Widget {$widgetId} not found.");
        }

        return $widget;
    }
}
