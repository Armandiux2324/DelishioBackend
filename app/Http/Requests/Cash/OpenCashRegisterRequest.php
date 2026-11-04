<?php

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['opening_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'id' => ['missing'], 'restaurant_id' => ['missing'], 'opened_by_employee_id' => ['missing'], 'opened_at' => ['missing'],
            'closed_by_employee_id' => ['missing'], 'counted_cash' => ['missing'], 'expected_cash' => ['missing'], 'difference' => ['missing'], 'closed_at' => ['missing']];
    }
}
