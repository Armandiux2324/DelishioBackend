<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * RFSA-003 - Edición de Restaurante: información general, no toca
     * al administrador ni los campos de RFA-055 (eso lo configura el
     * propio Administrador, no el Super Administrador).
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_account_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\Aacct_[a-zA-Z0-9]+\z/', Rule::unique('restaurants', 'stripe_account_id')->ignore($this->route('restaurant')?->id)],
        ];
    }
}
