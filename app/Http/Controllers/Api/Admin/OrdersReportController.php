<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\OrdersReportRequest;
use App\Http\Resources\OrderReportResource;
use App\Services\Reports\OrdersReport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrdersReportController extends Controller
{
    public function __invoke(OrdersReportRequest $request, OrdersReport $report): AnonymousResourceCollection
    {
        $filters = $request->safe()->only(['type', 'from', 'to']);
        $orders = $report->paginate($filters['type'], $request->startDate(), $request->endDateExclusive());

        return OrderReportResource::collection($orders->appends($filters))
            ->additional(['filters' => $filters + ['timezone' => config('app.timezone')]]);
    }
}
