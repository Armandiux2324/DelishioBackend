<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ProductsReportRequest;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\ProductsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductsReportController extends Controller
{
    public function __invoke(ProductsReportRequest $request, ProductsReport $report): JsonResponse|BinaryFileResponse
    {
        $filters = $request->validated();

        return ReportResponse::make($request, [
            'filters' => $filters,
            'timezone' => config('app.timezone'),
            ...DB::transaction(fn () => $report->summarize($request->startDate(), $request->endDateExclusive(), $filters)),
        ]);
    }
}
