<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\MenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MenuItemController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MenuItemResource::collection(MenuItem::with('ingredients')->orderBy('name')->orderBy('id')->paginate(20));
    }

    public function store(MenuItemRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $item = MenuItem::create($request->safe()->except('ingredients'));
            $this->syncIngredients($item, $request->validated('ingredients', []));

            return (new MenuItemResource($item->load('ingredients')))->response()->setStatusCode(201);
        });
    }

    public function show(MenuItem $menuItem): MenuItemResource
    {
        return new MenuItemResource($menuItem->load('ingredients'));
    }

    public function update(MenuItemRequest $request, MenuItem $menuItem): MenuItemResource
    {
        return DB::transaction(function () use ($request, $menuItem) {
            $menuItem = MenuItem::whereKey($menuItem->id)->lockForUpdate()->firstOrFail();
            $menuItem->update($request->safe()->except('ingredients'));
            if ($request->has('ingredients')) {
                $this->syncIngredients($menuItem, $request->validated('ingredients'));
            }

            return new MenuItemResource($menuItem->refresh()->load('ingredients'));
        });
    }

    public function destroy(MenuItem $menuItem): Response
    {
        DB::transaction(fn () => MenuItem::whereKey($menuItem->id)->lockForUpdate()->firstOrFail()->delete());

        return response()->noContent();
    }

    private function syncIngredients(MenuItem $item, array $ingredients): void
    {
        $existing = $item->ingredients()->get()->keyBy('inventory_item_id');
        $incoming = collect($ingredients)->keyBy('inventory_item_id')->sortKeys();
        foreach ($existing as $id => $ingredient) {
            if (! $incoming->has($id)) {
                $ingredient->delete();
            }
        }
        foreach ($incoming as $id => $data) {
            if ($existing->has($id)) {
                $existing[$id]->update(['quantity' => $data['quantity']]);
            } else {
                $item->ingredients()->create($data);
            }
        }
    }
}
