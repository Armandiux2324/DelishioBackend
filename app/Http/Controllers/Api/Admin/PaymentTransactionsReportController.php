<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\PaymentTransactionsReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentTransactionsReportController extends Controller
{
    public function __invoke(Request $request, PaymentTransactionsReport $report): JsonResponse
    {
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'restaurant_id' => ['missing'],
        ]);
        $timezone = config('app.timezone');
        $from = CarbonImmutable::parse($range['from'], $timezone)->startOfDay();
        $until = CarbonImmutable::parse($range['to'], $timezone)->startOfDay()->addDay();

        return response()->json(['data' => [
            'from' => $range['from'],
            'to' => $range['to'],
            'timezone' => $timezone,
            ...$report->summarize($from, $until),
        ]]);
    }
}
