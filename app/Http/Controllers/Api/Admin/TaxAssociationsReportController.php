<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxAssociationResource;
use App\Services\Reports\TaxAssociationsReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxAssociationsReportController extends Controller
{
    public function __invoke(Request $request, TaxAssociationsReport $report): AnonymousResourceCollection
    {
        $request->validate([
            'restaurant_id' => ['missing'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);

        return TaxAssociationResource::collection($report->paginate());
    }
}
