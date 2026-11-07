<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DashboardRequest;
use App\Services\Reports\AdminDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, AdminDashboard $dashboard): JsonResponse
    {
        $timezone = config('app.timezone');
        $today = CarbonImmutable::today($timezone);
        $period = null;
        if ($request->has('from')) {
            $from = CarbonImmutable::parse($request->validated('from'), $timezone);
            $to = CarbonImmutable::parse($request->validated('to'), $timezone);
        } else {
            $period = $request->validated('period', 'today');
            $to = $period === 'yesterday' ? $today->subDay() : $today;
            $from = $period === 'week' ? $to->subDays(6) : $to;
        }

        return response()->json(['data' => [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => $timezone,
            ...DB::transaction(fn () => $dashboard->summarize($from, $to->addDay())),
        ]]);
    }
}
