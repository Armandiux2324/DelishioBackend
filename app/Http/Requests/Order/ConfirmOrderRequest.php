<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(['id', 'restaurant_id', 'waiter_employee_id', 'customer_id', 'cash_register_id', 'source', 'status',
            'subtotal', 'tax_total', 'discount_total', 'dual_pricing_applied', 'tip_amount', 'total', 'created_at', 'sent_at', 'closed_at',
            'items', 'inventory_movements', 'cancel_reason', 'cancel_authorized_by_employee_id', 'loyalty_redeemed_amount', 'surcharge_total'], ['missing']);
    }
}
