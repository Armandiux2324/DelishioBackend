<?php

namespace App\Http\Requests\ExternalPlatformSale;

use App\Models\MenuItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreExternalPlatformSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:doordash,ubereats,didi,rappi'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'commission_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2', 'lte:total_amount'],
            'sale_date' => ['sometimes', 'required', 'date'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:menu_item_id,description,quantity,unit_price'],
            'items.*.menu_item_id' => ['sometimes', 'bail', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! MenuItem::whereKey($value)->exists()) {
                    $fail(__('El elemento de menú seleccionado no está disponible.'));
                }
            }],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'id' => ['missing'], 'restaurant_id' => ['missing'], 'registered_by_employee_id' => ['missing'],
            'net_amount' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
