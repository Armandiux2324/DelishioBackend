<?php

namespace App\Http\Requests\Inventory;

use App\Support\RestaurantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryLossRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'reason' => ['required', 'bail', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen($value) > 65535) {
                    $fail(__('El motivo no debe exceder 65535 bytes.'));
                }
            }],
            'id' => ['missing'],
            'restaurant_id' => ['missing'],
            'registered_by_employee_id' => ['missing'],
            'created_at' => ['missing'],
        ];
    }
}
