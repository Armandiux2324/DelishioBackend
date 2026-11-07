<?php

namespace App\Http\Requests\Reports;

use App\Support\RestaurantContext;
use Illuminate\Validation\Rule;

class SalesSummaryRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'from_time' => ['required_with:to_time', 'date_format:H:i:s'],
            'to_time' => ['required_with:from_time', 'date_format:H:i:s', 'after_or_equal:from_time'],
            'cash_register_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('cash_registers', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())],
        ];
    }
}
