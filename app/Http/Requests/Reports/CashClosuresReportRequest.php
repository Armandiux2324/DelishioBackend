<?php

namespace App\Http\Requests\Reports;

use App\Support\RestaurantContext;
use Illuminate\Validation\Rule;

class CashClosuresReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'cash_register_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('cash_registers', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())],
        ];
    }
}
