<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class TaxClassRequest extends FormRequest
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
            'percentage' => [$presence, 'filled', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
            'id' => ['missing'],
            'restaurant_id' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
