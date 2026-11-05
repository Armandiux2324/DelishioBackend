<?php

namespace App\Http\Controllers\Api\Waiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\StoreCourtesyRequest;
use App\Http\Requests\Order\StoreOrderDiscountRequest;
use App\Http\Requests\Order\StoreTaxExemptionRequest;
use App\Http\Resources\AuthorizingAdminResource;
use App\Http\Resources\OrderResource;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderAdjustmentController extends Controller
{
    public function authorizers(): AnonymousResourceCollection
    {
        return AuthorizingAdminResource::collection(Employee::active()->with('user')->where('role', 'admin')->orderBy('user_id')->paginate(20));
    }

    public function discount(StoreOrderDiscountRequest $request, Order $order, OrderAdjustmentService $service): JsonResponse
    {
        return (new OrderResource($service->discount($order, $request->validated())))->response()->setStatusCode(201);
    }

    public function courtesy(StoreCourtesyRequest $request, Order $order, OrderAdjustmentService $service): JsonResponse
    {
        return (new OrderResource($service->discount($order, $request->validated(), true)))->response()->setStatusCode(201);
    }

    public function exempt(StoreTaxExemptionRequest $request, Order $order, OrderAdjustmentService $service): JsonResponse
    {
        return (new OrderResource($service->exempt($order, $request->validated())))->response()->setStatusCode(201);
    }

    public function cancel(CancelOrderRequest $request, Order $order, OrderAdjustmentService $service): OrderResource
    {
        return new OrderResource($service->cancel($order, $request->validated()));
    }

    public function cancelItem(CancelOrderRequest $request, Order $order, OrderItem $item, OrderAdjustmentService $service): OrderResource
    {
        return new OrderResource($service->cancel($order, $request->validated(), $item));
    }
}
