<?php

namespace App\Http\Requests\Reports;

class OrdersReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'type' => ['required', 'in:paid,open,online'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
