<?php

namespace App\Http\Requests\Catalog;

use App\Support\RestaurantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
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
            'super_category_id' => [$presence, 'filled', 'integer', Rule::exists('super_categories', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())->whereNull('deleted_at')],
            'description' => ['sometimes', 'bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen($value) > 65535) {
                    $fail(__('La descripción no debe exceder 65535 bytes.'));
                }
            }],
            'id' => ['missing'],
            'restaurant_id' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
