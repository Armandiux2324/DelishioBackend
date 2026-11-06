<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExternalPlatformSale\StoreExternalPlatformSaleRequest;
use App\Http\Resources\ExternalPlatformSaleResource;
use App\Models\ExternalPlatformSale;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalPlatformSaleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ExternalPlatformSaleResource::collection(ExternalPlatformSale::with('items')
            ->orderByDesc('sale_date')->orderByDesc('id')->paginate(20));
    }

    public function show(ExternalPlatformSale $externalPlatformSale): ExternalPlatformSaleResource
    {
        return new ExternalPlatformSaleResource($externalPlatformSale->load('items'));
    }

    public function store(StoreExternalPlatformSaleRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $menuIds = collect($data['items'])->pluck('menu_item_id')->filter()->unique()->sort()->values();
            $menus = MenuItem::whereIn('id', $menuIds)->orderBy('id')->lockForUpdate()->get();
            if ($menus->count() !== $menuIds->count()) {
                throw ValidationException::withMessages(['items' => __('Hay elementos de menú que ya no están disponibles.')]);
            }
            $sale = ExternalPlatformSale::create([
                'platform' => $data['platform'], 'total_amount' => $data['total_amount'], 'commission_amount' => $data['commission_amount'],
                'sale_date' => $data['sale_date'] ?? now(), 'registered_by_employee_id' => $request->user()->employee->user_id,
            ]);
            $sale->items()->createMany($data['items']);

            return (new ExternalPlatformSaleResource($sale->load('items')))->response()->setStatusCode(201);
        });
    }
}
