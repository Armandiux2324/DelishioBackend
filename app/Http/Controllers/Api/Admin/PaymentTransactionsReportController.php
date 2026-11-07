<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportDateRangeRequest;
use App\Services\Reports\PaymentTransactionsReport;
use Illuminate\Http\JsonResponse;

class PaymentTransactionsReportController extends Controller
{
    public function __invoke(ReportDateRangeRequest $request, PaymentTransactionsReport $report): JsonResponse
    {
        return response()->json(['data' => [
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'timezone' => config('app.timezone'),
            ...$report->summarize($request->startDate(), $request->endDateExclusive()),
        ]]);
    }
}
