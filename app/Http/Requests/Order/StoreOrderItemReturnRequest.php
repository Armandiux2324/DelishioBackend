<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderItemReturnRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['request_key' => strtolower((string) $this->header('Idempotency-Key'))]);
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'tax_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:1000'],
            'returned_at' => ['required', 'date_format:Y-m-d H:i:s', 'before_or_equal:now'],
        ] + array_fill_keys(['id', 'order_id', 'order_item_id', 'restaurant_id', 'recorded_by_employee_id', 'created_at'], ['missing']);
    }
}
