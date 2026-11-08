<?php

namespace App\Http\Requests\Reports;

use App\Support\RestaurantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class ProductsReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        $restaurantId = app(RestaurantContext::class)->id();

        return parent::rules() + [
            'super_category_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('super_categories', 'id')->where('restaurant_id', $restaurantId)],
            'category_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1',
                Rule::exists('categories', 'id')->where(fn (Builder $categories) => $categories->whereIn('super_category_id',
                    fn (Builder $supers) => $supers->from('super_categories')->select('id')->where('restaurant_id', $restaurantId)))],
            'modifier' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
