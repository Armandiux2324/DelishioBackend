<?php

namespace App\Http\Requests\Reports;

use App\Support\RestaurantContext;
use Illuminate\Validation\Rule;

class EmployeeReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        return $rules + [
            'employee_id' => ['sometimes', 'required', 'integer', Rule::exists('employees', 'user_id')
                ->where('restaurant_id', app(RestaurantContext::class)->id())],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'waiter', 'chef'])],
        ];
    }
}
