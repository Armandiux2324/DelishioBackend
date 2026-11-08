<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ProductsReportRequest;
use App\Services\Reports\ProductsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductsReportController extends Controller
{
    public function __invoke(ProductsReportRequest $request, ProductsReport $report): JsonResponse
    {
        $filters = $request->validated();

        return response()->json(['data' => [
            'filters' => $filters,
            'timezone' => config('app.timezone'),
            ...DB::transaction(fn () => $report->summarize($request->startDate(), $request->endDateExclusive(), $filters)),
        ]]);
    }
}
