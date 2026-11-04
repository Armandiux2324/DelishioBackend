<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\InventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InventoryItemController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InventoryItemResource::collection(InventoryItem::orderBy('name')->orderBy('id')->paginate(20));
    }

    public function store(InventoryItemRequest $request): JsonResponse
    {
        return (new InventoryItemResource(InventoryItem::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(InventoryItem $inventoryItem): InventoryItemResource
    {
        return new InventoryItemResource($inventoryItem);
    }

    public function update(InventoryItemRequest $request, InventoryItem $inventoryItem): InventoryItemResource
    {
        return DB::transaction(function () use ($request, $inventoryItem) {
            $inventoryItem = InventoryItem::whereKey($inventoryItem->id)->lockForUpdate()->firstOrFail();
            $inventoryItem->update($request->validated());

            return new InventoryItemResource($inventoryItem->refresh());
        });
    }

    public function destroy(InventoryItem $inventoryItem): Response
    {
        DB::transaction(function () use ($inventoryItem) {
            InventoryItem::whereKey($inventoryItem->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return response()->noContent();
    }
}
