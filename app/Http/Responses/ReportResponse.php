<?php

namespace App\Http\Responses;

use App\Exports\ReportWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as Writer;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportResponse
{
    public static function wantsXlsx(Request $request): bool
    {
        return $request->query('format') === 'xlsx';
    }

    public static function make(Request $request, array $data): JsonResponse|BinaryFileResponse
    {
        if (! self::wantsXlsx($request)) {
            return response()->json(['data' => $data]);
        }

        $report = str($request->route()->getName())->after('admin.reports.')->toString();

        return Excel::download(new ReportWorkbook(['report' => $report, ...$data]), $report.'.xlsx', Writer::XLSX, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
