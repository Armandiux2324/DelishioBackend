<?php

namespace App\Http\Requests\Order;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $order = $this->route('order');
        $sent = $order?->sent_at !== null || $order?->status === 'sent';

        return ['reason' => ['required', 'bail', 'string', function (string $attribute, mixed $value, Closure $fail) {
            if (strlen($value) > 65535) {
                $fail(__('El motivo no debe exceder 65535 bytes.'));
            }
        }],
            'admin_user_id' => ['sometimes', 'required', 'integer'],
            'pin' => ['nullable', Rule::requiredIf($sent), 'required_with:admin_user_id', 'string', 'regex:/\\A[0-9]{4,6}\\z/'],
            'cancelled' => ['missing'], 'cancel_reason' => ['missing'], 'cancelled_by_employee_id' => ['missing'],
            'cancel_authorized_by_employee_id' => ['missing'], 'status' => ['missing'], 'order_id' => ['missing'], 'kind' => ['missing'], 'requested_by_employee_id' => ['missing'], 'authorized_by_employee_id' => ['missing'], 'work_shift_id' => ['missing'], 'created_at' => ['missing'], 'id' => ['missing'], 'restaurant_id' => ['missing'],
        ];
    }
}
