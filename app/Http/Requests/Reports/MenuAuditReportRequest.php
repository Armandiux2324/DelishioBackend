<?php

namespace App\Http\Requests\Reports;

use App\Support\RestaurantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class MenuAuditReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        $restaurantId = app(RestaurantContext::class)->id();

        return parent::rules() + [
            'employee_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('employees', 'user_id')->where('restaurant_id', $restaurantId)],
            'menu_item_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('menu_items', 'id')->where(fn (Builder $items) => $items->whereIn('category_id',
                    fn (Builder $categories) => $categories->from('categories')
                        ->join('super_categories', 'categories.super_category_id', '=', 'super_categories.id')
                        ->where('super_categories.restaurant_id', $restaurantId)->select('categories.id')))],
        ];
    }
}
