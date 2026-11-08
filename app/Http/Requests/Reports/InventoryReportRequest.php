<?php

namespace App\Http\Requests\Reports;

use App\Support\RestaurantContext;
use Illuminate\Validation\Rule;

class InventoryReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        $rules = $this->routeIs('admin.reports.inventory-value')
            ? ['from' => ['missing'], 'to' => ['missing'], 'restaurant_id' => ['missing']]
            : parent::rules();

        return $rules + [
            'inventory_item_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('inventory_items', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())],
        ];
    }
}
