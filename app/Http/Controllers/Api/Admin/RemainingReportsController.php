<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\EmployeeReportRequest;
use App\Http\Requests\Reports\ReportDateRangeRequest;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\EmployeeProductSalesReport;
use App\Services\Reports\ExternalPlatformSalesReport;
use App\Services\Reports\LaborReports;
use App\Services\Reports\OrderAuditReports;
use App\Services\Reports\ProfitMarginsReport;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RemainingReportsController extends Controller
{
    public function __invoke(ReportDateRangeRequest $request, string $report): JsonResponse|BinaryFileResponse
    {
        return $this->respond($request, match ($report) {
            'courtesies' => fn ($from, $until) => app(OrderAuditReports::class)->discounts($from, $until, 'courtesy'),
            'discounts' => fn ($from, $until) => app(OrderAuditReports::class)->discounts($from, $until, 'discount'),
            'profit-margins' => app(ProfitMarginsReport::class)->summarize(...),
            'taxes-and-exemptions' => app(OrderAuditReports::class)->taxes(...),
            'tips' => app(OrderAuditReports::class)->tips(...),
            'cancellations' => app(OrderAuditReports::class)->cancellations(...),
            'external-platform-sales' => app(ExternalPlatformSalesReport::class)->summarize(...),
        });
    }

    public function employees(EmployeeReportRequest $request, string $report): JsonResponse|BinaryFileResponse
    {
        return $this->respond($request, match ($report) {
            'employee-product-sales' => app(EmployeeProductSalesReport::class)->summarize(...),
            'work-shifts' => app(LaborReports::class)->shifts(...),
            'payroll' => app(LaborReports::class)->payroll(...),
            'sales-vs-labor' => app(LaborReports::class)->salesVsLabor(...),
        });
    }

    private function respond(ReportDateRangeRequest $request, Closure $report): JsonResponse|BinaryFileResponse
    {
        return ReportResponse::make($request, [
            'filters' => $request->validated(),
            'timezone' => config('app.timezone'),
            ...DB::transaction(fn () => $report($request->startDate(), $request->endDateExclusive(), $request->validated())),
        ]);
    }
}
