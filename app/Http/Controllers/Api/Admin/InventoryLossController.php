<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryLossRequest;
use App\Http\Resources\InventoryLossResource;
use App\Models\InventoryItem;
use App\Models\InventoryLoss;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryLossController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InventoryLossResource::collection(InventoryLoss::orderByDesc('created_at')->orderByDesc('id')->paginate(20));
    }

    public function show(InventoryLoss $inventoryLoss): InventoryLossResource
    {
        return new InventoryLossResource($inventoryLoss);
    }

    public function store(StoreInventoryLossRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $item = InventoryItem::whereKey($data['inventory_item_id'])->lockForUpdate()->firstOrFail();
            $remaining = BigDecimal::of($item->current_stock)->minus((string) $data['quantity']);
            if ($remaining->isNegative()) {
                throw ValidationException::withMessages(['quantity' => __('La pérdida supera la existencia disponible.')]);
            }

            $item->update(['current_stock' => (string) $remaining]);
            $loss = InventoryLoss::create($data + [
                'registered_by_employee_id' => $request->user()->employee->user_id,
            ]);

            return (new InventoryLossResource($loss))->response()->setStatusCode(201);
        });
    }
}
