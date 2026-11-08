<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\OrdersReportRequest;
use App\Http\Resources\OrderReportResource;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\OrdersReport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrdersReportController extends Controller
{
    public function __invoke(OrdersReportRequest $request, OrdersReport $report): AnonymousResourceCollection|BinaryFileResponse
    {
        $filters = $request->safe()->only(['type', 'from', 'to']);
        $orders = $report->query($filters['type'], $request->startDate(), $request->endDateExclusive());
        $metadata = ['filters' => $filters + ['timezone' => config('app.timezone')]];

        if (ReportResponse::wantsXlsx($request)) {
            return ReportResponse::make($request, $metadata + [
                'orders' => OrderReportResource::collection($orders->get())->resolve($request),
            ]);
        }

        return OrderReportResource::collection($orders->paginate(20)->appends($filters))->additional($metadata);
    }
}
