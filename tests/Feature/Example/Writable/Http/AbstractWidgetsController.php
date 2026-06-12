<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Http;

use CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets\WidgetData;
use CodeWithAgents\OpenApiLaravel\Tests\Feature\Example\Writable\Data\Widgets\WidgetWritableData;

abstract class AbstractWidgetsController
{
    /**
     * POST /widgets
     *
     * Create a widget.
     *
     * Responds with HTTP 201 (set by the generated route).
     */
    abstract public function createWidget(WidgetWritableData $widget): WidgetData;

    /**
     * GET /widgets/{widgetId}
     *
     * Get a widget by id.
     */
    abstract public function getWidgetById(int $widgetId): WidgetData;
}
