<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Http\Controllers\Api;

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\OrderData;
use Illuminate\Http\JsonResponse;

abstract class AbstractStoreController
{
    /**
     * GET /store/inventory
     *
     * Returns pet inventories by status.
     */
    abstract public function getInventory(): JsonResponse;

    /**
     * POST /store/order
     *
     * Place an order for a pet.
     */
    abstract public function placeOrder(OrderData $order): OrderData;

    /**
     * GET /store/order/{orderId}
     *
     * Find purchase order by ID.
     */
    abstract public function getOrderById(int $orderId): OrderData;

    /**
     * DELETE /store/order/{orderId}
     *
     * Delete purchase order by identifier.
     */
    abstract public function deleteOrder(int $orderId): JsonResponse;
}
