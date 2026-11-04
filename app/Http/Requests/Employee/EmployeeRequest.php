<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $role = $this->input('role', $employee?->role);
        $stationRequired = $this->isMethod('POST') || $employee?->station === null;

        return [
            'name' => [$presence, 'filled', 'string', 'max:255'],
            'email' => [$presence, 'filled', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'username' => ['sometimes', 'nullable', 'string', 'lowercase', 'alpha_dash:ascii', 'max:50', Rule::unique('users', 'username')->ignore($employee?->user_id)],
            'locale' => ['sometimes', 'in:en,es'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => [$presence, 'filled', 'in:admin,waiter,chef'],
            'station' => $role === 'chef' ? [$stationRequired ? 'required' : 'sometimes', 'filled', 'in:kitchen,bar'] : ['prohibited'],
            'hourly_rate' => [$presence, 'filled', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'pin' => ['sometimes', 'string', 'regex:/\\A[0-9]{4,6}\\z/'],
            'password' => [$presence, 'filled', 'confirmed', Password::defaults()],
            'active' => ['sometimes', 'boolean'],
            'id' => ['missing'],
            'user_id' => ['missing'],
            'restaurant_id' => ['missing'],
            'user_type' => ['missing'],
            'email_verified_at' => ['missing'],
            'two_factor_enabled' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
