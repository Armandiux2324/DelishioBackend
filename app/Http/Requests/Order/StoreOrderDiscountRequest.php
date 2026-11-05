<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_user_id' => ['required', 'integer'], 'pin' => ['required', 'string', 'regex:/\\A[0-9]{4,6}\\z/'],
            'order_item_id' => ['sometimes', 'nullable', 'integer'],
            'percentage' => ['nullable', 'required_without:amount', 'prohibits:amount', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'amount' => ['nullable', 'required_without:percentage', 'prohibits:percentage', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'order_id' => ['missing'], 'kind' => ['missing'], 'requested_by_employee_id' => ['missing'], 'authorized_by_employee_id' => ['missing'], 'work_shift_id' => ['missing'], 'created_at' => ['missing'], 'id' => ['missing'], 'restaurant_id' => ['missing'],
            'discount_limit_per_shift' => ['missing'],
        ];
    }
}
