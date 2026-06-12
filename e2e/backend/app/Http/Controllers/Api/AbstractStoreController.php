<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Store\OrderData;
use Illuminate\Http\JsonResponse;

abstract class AbstractStoreController
{
    /**
     * GET /store/inventory
     *
     * Returns pet inventories by status.
     */
    abstract public function index(): JsonResponse;

    /**
     * POST /store/order
     *
     * Place an order for a pet.
     */
    abstract public function store(OrderData $order): OrderData;

    /**
     * GET /store/order/{orderId}
     *
     * Find purchase order by ID.
     */
    abstract public function show(int $orderId): OrderData;

    /**
     * DELETE /store/order/{orderId}
     *
     * Delete purchase order by identifier.
     */
    abstract public function destroy(int $orderId): JsonResponse;
}
