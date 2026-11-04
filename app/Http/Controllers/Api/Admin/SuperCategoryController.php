<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SuperCategoryRequest;
use App\Http\Resources\SuperCategoryResource;
use App\Models\SuperCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SuperCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SuperCategoryResource::collection(SuperCategory::orderBy('name')->orderBy('id')->paginate(20));
    }

    public function store(SuperCategoryRequest $request): JsonResponse
    {
        $superCategory = DB::transaction(fn () => SuperCategory::create($request->validated()));

        return (new SuperCategoryResource($superCategory))->response()->setStatusCode(201);
    }

    public function show(SuperCategory $superCategory): SuperCategoryResource
    {
        return new SuperCategoryResource($superCategory);
    }

    public function update(SuperCategoryRequest $request, SuperCategory $superCategory): SuperCategoryResource
    {
        return DB::transaction(function () use ($request, $superCategory) {
            $superCategory = SuperCategory::whereKey($superCategory->id)->lockForUpdate()->firstOrFail();
            $superCategory->update($request->validated());

            return new SuperCategoryResource($superCategory->refresh());
        });
    }

    public function destroy(SuperCategory $superCategory): Response
    {
        DB::transaction(function () use ($superCategory) {
            SuperCategory::whereKey($superCategory->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return response()->noContent();
    }
}
