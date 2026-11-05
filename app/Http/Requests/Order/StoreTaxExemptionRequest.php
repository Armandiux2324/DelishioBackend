<?php

namespace App\Http\Requests\Order;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaxExemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['order_item_id' => ['sometimes', 'nullable', 'integer'], 'reason' => ['required', 'bail', 'string',
            function (string $attribute, mixed $value, Closure $fail) {
                if (strlen($value) > 65535) {
                    $fail(__('El motivo no debe exceder 65535 bytes.'));
                }
            }],
            'order_id' => ['missing'], 'kind' => ['missing'], 'requested_by_employee_id' => ['missing'], 'authorized_by_employee_id' => ['missing'], 'work_shift_id' => ['missing'], 'created_at' => ['missing'], 'id' => ['missing'], 'restaurant_id' => ['missing'], 'admin_user_id' => ['missing'], 'pin' => ['missing']];
    }
}
