<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxAssociationResource;
use App\Http\Responses\ReportResponse;
use App\Services\Reports\TaxAssociationsReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxAssociationsReportController extends Controller
{
    public function __invoke(Request $request, TaxAssociationsReport $report): AnonymousResourceCollection|BinaryFileResponse
    {
        $request->validate([
            'restaurant_id' => ['missing'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);

        $items = $report->query();
        if (ReportResponse::wantsXlsx($request)) {
            return ReportResponse::make($request, [
                'items' => TaxAssociationResource::collection($items->get())->resolve($request),
            ]);
        }

        return TaxAssociationResource::collection($items->paginate(20));
    }
}
