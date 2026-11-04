<?php

namespace App\Http\Requests\Cash;

use App\Models\Employee;
use App\Support\RestaurantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $out = $this->input('type') === 'cash_out';
        $payroll = $out && $this->input('reason') === 'payroll';

        return [
            'cash_register_id' => ['required', 'integer', Rule::exists('cash_registers', 'id')->where('restaurant_id', app(RestaurantContext::class)->id())],
            'type' => ['required', 'in:cash_in,cash_out'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => $out ? ['required', 'in:payroll,minor_purchase,other'] : ['prohibited'],
            'related_employee_id' => $payroll ? ['bail', 'required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! Employee::whereKey($value)->exists()) {
                    $fail(__('El empleado seleccionado no está disponible.'));
                }
            }] : ['prohibited'],
            'payroll_period_start' => $payroll ? ['required', 'date_format:Y-m-d'] : ['prohibited'],
            'payroll_period_end' => $payroll ? ['required', 'date_format:Y-m-d', 'after_or_equal:payroll_period_start'] : ['prohibited'],
            'id' => ['missing'], 'restaurant_id' => ['missing'], 'registered_by_employee_id' => ['missing'], 'created_at' => ['missing'],
        ];
    }
}
