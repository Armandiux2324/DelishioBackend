<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::orderBy('name')->orderBy('id')->paginate(20));
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(fn () => Category::create($request->validated()));

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        return DB::transaction(function () use ($request, $category) {
            $category = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $category->update($request->validated());

            return new CategoryResource($category->refresh());
        });
    }

    public function destroy(Category $category): Response
    {
        DB::transaction(function () use ($category) {
            Category::whereKey($category->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return response()->noContent();
    }
}
