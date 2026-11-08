<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CashClosuresReportRequest;
use App\Http\Requests\Reports\InventoryReportRequest;
use App\Http\Requests\Reports\MenuAuditReportRequest;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\CashClosuresReport;
use App\Services\Reports\InventoryLossesReport;
use App\Services\Reports\InventoryValueReport;
use App\Services\Reports\MenuAuditReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CashMenuInventoryReportsController extends Controller
{
    public function cashClosures(CashClosuresReportRequest $request, CashClosuresReport $report): JsonResponse|BinaryFileResponse
    {
        return ReportResponse::make($request, [
            'filters' => $request->validated(),
            'timezone' => config('app.timezone'),
            ...$report->summarize($request->startDate(), $request->endDateExclusive(), $request->validated('cash_register_id')),
        ]);
    }

    public function menuAudit(MenuAuditReportRequest $request, MenuAuditReport $report): JsonResponse|BinaryFileResponse
    {
        return ReportResponse::make($request, [
            'filters' => $request->validated(),
            'timezone' => config('app.timezone'),
            ...$report->summarize($request->startDate(), $request->endDateExclusive(), $request->validated()),
        ]);
    }

    public function inventoryValue(InventoryReportRequest $request, InventoryValueReport $report): JsonResponse|BinaryFileResponse
    {
        return ReportResponse::make($request, [
            'filters' => $request->validated(),
            ...$report->summarize($request->validated('inventory_item_id')),
        ]);
    }

    public function inventoryLosses(InventoryReportRequest $request, InventoryLossesReport $report): JsonResponse|BinaryFileResponse
    {
        return ReportResponse::make($request, [
            'filters' => $request->validated(),
            'timezone' => config('app.timezone'),
            ...$report->summarize($request->startDate(), $request->endDateExclusive(), $request->validated('inventory_item_id')),
        ]);
    }
}
