<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Examples\Petstore\Http\Controllers\Api;

use CodeWithAgents\OpenApiLaravel\Examples\Petstore\Data\Store\OrderData;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hand-written concrete controller extending the GENERATED
 * AbstractStoreController. Implements the store/order flow against the
 * in-memory store.
 */
final class StoreController extends AbstractStoreController
{
    public function __construct(
        private readonly InMemoryStore $store,
    ) {}

    public function getInventory(): JsonResponse
    {
        // The spec types this response as a free-form object (status => count),
        // so the generator returns JsonResponse and we shape the payload here.
        return new JsonResponse($this->store->inventory());
    }

    public function placeOrder(OrderData $order): OrderData
    {
        return $this->store->putOrder($order);
    }

    public function getOrderById(int $orderId): OrderData
    {
        $order = $this->store->findOrder($orderId);

        if ($order === null) {
            throw new NotFoundHttpException("Order {$orderId} not found.");
        }

        return $order;
    }

    public function deleteOrder(int $orderId): JsonResponse
    {
        $deleted = $this->store->deleteOrder($orderId);

        if (! $deleted) {
            throw new NotFoundHttpException("Order {$orderId} not found.");
        }

        return new JsonResponse(null, 204);
    }
}
