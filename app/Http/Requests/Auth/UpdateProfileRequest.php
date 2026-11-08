<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'nullable', 'string', 'lowercase', 'alpha_dash:ascii', 'max:50',
                Rule::unique('users', 'username')->ignore($user->id)],
            'locale' => ['sometimes', 'required', 'in:en,es'],
            'phone' => $user->user_type === 'super_admin' ? ['missing'] : ['sometimes', 'nullable', 'string', 'max:20'],
        ] + array_fill_keys([
            'id', 'user_id', 'user_type', 'email', 'email_verified_at', 'password', 'password_confirmation',
            'remember_token', 'two_factor_enabled', 'active', 'restaurant_id', 'role', 'station', 'hourly_rate',
            'pin', 'qr_code', 'employee', 'customer', 'created_at', 'updated_at', 'deleted_at',
        ], ['missing']);
    }
}
