<?php

namespace App\Http\Controllers\Api\Waiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CloseOrderRequest;
use App\Http\Requests\Order\LinkOrderCustomerRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderCheckoutService;
use Illuminate\Http\JsonResponse;

class OrderCheckoutController extends Controller
{
    public function linkCustomer(LinkOrderCustomerRequest $request, Order $order, OrderCheckoutService $service): OrderResource
    {
        return new OrderResource($service->linkCustomer($order, $request->validated('qr_code')));
    }

    public function preview(CloseOrderRequest $request, Order $order, OrderCheckoutService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview($order, $request->validated())]);
    }

    public function close(CloseOrderRequest $request, Order $order, OrderCheckoutService $service): OrderResource
    {
        return new OrderResource($service->close($order, $request->validated()));
    }
}
