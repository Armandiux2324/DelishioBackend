<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class InventoryItemRequest extends FormRequest
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
            'unit' => [$presence, 'filled', 'in:g,kg,oz,lb,ml,l,fl_oz,gal,pza'],
            'unit_cost' => [$presence, 'filled', 'numeric', 'min:0', 'max:999999.9999', 'decimal:0,4'],
            'current_stock' => [$presence, 'filled', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'id' => ['missing'],
            'restaurant_id' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
