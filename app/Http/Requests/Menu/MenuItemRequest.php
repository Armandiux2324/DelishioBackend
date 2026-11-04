<?php

namespace App\Http\Requests\Menu;

use App\Models\Category;
use App\Support\RestaurantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'category_id' => [$presence, 'bail', 'filled', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! Category::whereKey($value)->exists()) {
                    $fail(__('La categoría seleccionada no está disponible.'));
                }
            }],
            'name' => [$presence, 'filled', 'string', 'max:255'],
            'sale_price' => [$presence, 'filled', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'description' => ['sometimes', 'bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen($value) > 65535) {
                    $fail(__('La descripción no debe exceder 65535 bytes.'));
                }
            }],
            'image_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'legal_age_required' => ['sometimes', 'boolean'],
            'preparation_station' => ['sometimes', 'in:none,kitchen,bar'],
            'active' => ['sometimes', 'boolean'],
            'ingredients' => ['sometimes', 'array', 'list'],
            'ingredients.*' => ['required', 'array:inventory_item_id,quantity'],
            'ingredients.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')
                ->where('restaurant_id', app(RestaurantContext::class)->id())->whereNull('deleted_at')],
            'ingredients.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'id' => ['missing'],
            'restaurant_id' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
