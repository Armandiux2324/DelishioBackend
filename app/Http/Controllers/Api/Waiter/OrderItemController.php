<?php

namespace App\Http\Controllers\Api\Waiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmOrderRequest;
use App\Http\Requests\Order\OrderItemRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\WaiterOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrderItemController extends Controller
{
    public function store(OrderItemRequest $request, Order $order, WaiterOrderService $service): JsonResponse
    {
        return (new OrderResource($service->addItem($order, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(OrderItemRequest $request, Order $order, OrderItem $item, WaiterOrderService $service): OrderResource
    {
        return new OrderResource($service->updateItem($order, $item, $request->validated()));
    }

    public function destroy(ConfirmOrderRequest $request, Order $order, OrderItem $item, WaiterOrderService $service): Response
    {
        $service->removeItem($order, $item);

        return response()->noContent();
    }
}
