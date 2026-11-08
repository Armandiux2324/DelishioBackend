<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\SalesSummaryRequest;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\SalesSummaryReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesSummaryReportController extends Controller
{
    public function __invoke(SalesSummaryRequest $request, SalesSummaryReport $report): JsonResponse|BinaryFileResponse
    {
        $fromTime = $request->validated('from_time');
        $toTime = $request->validated('to_time');
        $cashRegisterId = $request->has('cash_register_id') ? $request->integer('cash_register_id') : null;

        return ReportResponse::make($request, [
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'cash_register_id' => $cashRegisterId,
            'timezone' => config('app.timezone'),
            ...$report->summarize($request->startDate(), $request->endDateExclusive(), $fromTime, $toTime, $cashRegisterId),
        ]);
    }
}
