<?php

namespace App\Http\Controllers\Api\Waiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ConfirmOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\WaiterOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection(Order::with('items.menuItem')->where('waiter_employee_id', $request->user()->id)->orderByDesc('id')->paginate(20));
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless($order->waiter_employee_id === $request->user()->id, 404);

        return new OrderResource($order->load('items.menuItem'));
    }

    public function store(StoreOrderRequest $request, WaiterOrderService $service): JsonResponse
    {
        return (new OrderResource($service->create($request->validated())))->response()->setStatusCode(201);
    }

    public function confirm(ConfirmOrderRequest $request, Order $order, WaiterOrderService $service): OrderResource
    {
        return new OrderResource($service->confirm($order));
    }
}
