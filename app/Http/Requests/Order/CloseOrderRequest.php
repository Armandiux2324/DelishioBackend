<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class CloseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payments' => ['present', 'array', 'list', 'max:3'],
            'payments.*' => ['required', 'array:method,amount'],
            'payments.*.method' => ['required', 'string', 'in:cash,card,transfer', 'distinct:strict'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'tip_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'redeem_points' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'id' => ['missing'], 'restaurant_id' => ['missing'], 'order_id' => ['missing'], 'customer_id' => ['missing'],
            'waiter_employee_id' => ['missing'], 'cash_register_id' => ['missing'], 'status' => ['missing'],
            'subtotal' => ['missing'], 'tax_total' => ['missing'], 'discount_total' => ['missing'], 'total' => ['missing'],
            'dual_pricing_applied' => ['missing'], 'closed_at' => ['missing'], 'created_at' => ['missing'], 'sent_at' => ['missing'],
            'loyalty_redeemed_amount' => ['missing'], 'surcharge_total' => ['missing'],
            'loyalty_factor' => ['missing'], 'loyalty_redeem_value' => ['missing'], 'dual_pricing_percentage' => ['missing'],
        ];
    }
}
