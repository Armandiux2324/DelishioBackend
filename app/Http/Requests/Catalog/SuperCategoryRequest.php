<?php

namespace App\Http\Requests\Catalog;

use App\Support\RestaurantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuperCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'filled', 'string', 'max:255'],
            'tax_class_id' => [$presence, 'filled', 'integer', Rule::exists('tax_classes', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())->whereNull('deleted_at')],
            'id' => ['missing'],
            'restaurant_id' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
