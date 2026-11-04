<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\PromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PromotionResource::collection(Promotion::with(['menuItems', 'categories'])->orderBy('name')->orderBy('id')->paginate(20));
    }

    public function store(PromotionRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $promotion = Promotion::create($request->safe()->except(['menu_item_ids', 'category_ids']));
            $this->syncTargets($promotion, $request->validated());

            return (new PromotionResource($promotion->load(['menuItems', 'categories'])))->response()->setStatusCode(201);
        });
    }

    public function show(Promotion $promotion): PromotionResource
    {
        return new PromotionResource($promotion->load(['menuItems', 'categories']));
    }

    public function update(PromotionRequest $request, Promotion $promotion): PromotionResource
    {
        return DB::transaction(function () use ($request, $promotion) {
            $promotion = Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $request->validateState($promotion, $request->validated());
            $promotion->fill($request->safe()->except(['menu_item_ids', 'category_ids']));
            if ($promotion->condition_type === 'category_discount') {
                $promotion->condition_value = null;
            }
            $promotion->save();
            $this->syncTargets($promotion, $request->validated());

            return new PromotionResource($promotion->refresh()->load(['menuItems', 'categories']));
        });
    }

    public function destroy(Promotion $promotion): Response
    {
        DB::transaction(fn () => Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail()->delete());

        return response()->noContent();
    }

    private function syncTargets(Promotion $promotion, array $data): void
    {
        if (array_key_exists('menu_item_ids', $data)) {
            $promotion->menuItems()->sync(collect($data['menu_item_ids'])->sort()->values()->all());
        }
        if (array_key_exists('category_ids', $data)) {
            $promotion->categories()->sync(collect($data['category_ids'])->sort()->values()->all());
        }
    }
}
