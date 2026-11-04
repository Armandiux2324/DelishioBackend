<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\TaxClassRequest;
use App\Http\Resources\TaxClassResource;
use App\Models\TaxClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TaxClassController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TaxClassResource::collection(TaxClass::orderBy('name')->orderBy('id')->paginate(20));
    }

    public function store(TaxClassRequest $request): JsonResponse
    {
        $taxClass = TaxClass::create($request->validated());

        return (new TaxClassResource($taxClass))->response()->setStatusCode(201);
    }

    public function show(TaxClass $taxClass): TaxClassResource
    {
        return new TaxClassResource($taxClass);
    }

    public function update(TaxClassRequest $request, TaxClass $taxClass): TaxClassResource
    {
        return DB::transaction(function () use ($request, $taxClass) {
            $taxClass = TaxClass::whereKey($taxClass->id)->lockForUpdate()->firstOrFail();
            $taxClass->update($request->validated());

            return new TaxClassResource($taxClass->refresh());
        });
    }

    public function destroy(TaxClass $taxClass): Response
    {
        DB::transaction(function () use ($taxClass) {
            TaxClass::whereKey($taxClass->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return response()->noContent();
    }
}
