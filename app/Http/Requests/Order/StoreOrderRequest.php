<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $delivery = $this->input('service_type') === 'delivery';

        return [
            'service_type' => ['required', 'in:dine_in,takeout,delivery'],
            'table_number' => $this->input('service_type') === 'dine_in' ? ['required', 'string', 'max:255'] : ['prohibited'],
            'delivery_address' => $delivery ? ['sometimes', 'nullable', 'string', 'max:255'] : ['prohibited'],
            'delivery_fee' => $delivery ? ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'] : ['prohibited'],
            'id' => ['missing'], 'restaurant_id' => ['missing'], 'waiter_employee_id' => ['missing'], 'customer_id' => ['missing'],
            'cash_register_id' => ['missing'], 'source' => ['missing'], 'status' => ['missing'], 'subtotal' => ['missing'],
            'tax_total' => ['missing'], 'discount_total' => ['missing'], 'dual_pricing_applied' => ['missing'], 'tip_amount' => ['missing'],
            'total' => ['missing'], 'created_at' => ['missing'], 'sent_at' => ['missing'], 'closed_at' => ['missing'],
            'items' => ['missing'],
        ];
    }
}
