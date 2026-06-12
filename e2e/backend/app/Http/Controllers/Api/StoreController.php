<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Store\OrderData;
use App\Support\PetStore;
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
        private readonly PetStore $store,
    ) {}

    public function index(): JsonResponse
    {
        // The spec types this response as a free-form object (status => count),
        // so the generator returns JsonResponse and we shape the payload here.
        return new JsonResponse($this->store->inventory());
    }

    public function store(OrderData $order): OrderData
    {
        return $this->store->putOrder($order);
    }

    public function show(int $orderId): OrderData
    {
        $order = $this->store->findOrder($orderId);

        if ($order === null) {
            throw new NotFoundHttpException("Order {$orderId} not found.");
        }

        return $order;
    }

    public function destroy(int $orderId): JsonResponse
    {
        $deleted = $this->store->deleteOrder($orderId);

        if (! $deleted) {
            throw new NotFoundHttpException("Order {$orderId} not found.");
        }

        return new JsonResponse(null, 204);
    }
}
