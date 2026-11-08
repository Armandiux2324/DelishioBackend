<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportDateRangeRequest;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\PaymentTransactionsReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PaymentTransactionsReportController extends Controller
{
    public function __invoke(ReportDateRangeRequest $request, PaymentTransactionsReport $report): JsonResponse|BinaryFileResponse
    {
        return ReportResponse::make($request, [
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'timezone' => config('app.timezone'),
            ...$report->summarize($request->startDate(), $request->endDateExclusive()),
        ]);
    }
}
