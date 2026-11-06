<?php

namespace App\Http\Requests\Customer;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdvanceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['request_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $text = ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
            if (strlen($value) > 65535) {
                $fail(__('El texto no debe exceder 65535 bytes.'));
            }
        }];

        return ['request_key' => ['required', 'uuid'], 'service_type' => ['sometimes', 'required', 'in:takeout'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:menu_item_id,quantity,modifiers,kitchen_notes'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.modifiers' => $text, 'items.*.kitchen_notes' => $text,
            'customer_id' => ['missing'], 'restaurant_id' => ['missing'], 'source' => ['missing'], 'status' => ['missing'],
            'waiter_employee_id' => ['missing'], 'cash_register_id' => ['missing'], 'total' => ['missing'],
            'subtotal' => ['missing'], 'tax_total' => ['missing'], 'discount_total' => ['missing'], 'tip_amount' => ['missing'],
            'delivery_fee' => ['missing'], 'delivery_address' => ['missing'], 'table_number' => ['missing'],
            'stripe_account_id' => ['missing'], 'payment_reference' => ['missing'], 'payment_confirmed' => ['missing'],
            'redeem_points' => ['missing'], 'promotion_id' => ['missing'], 'dual_pricing_applied' => ['missing'],
            'loyalty_redeemed_amount' => ['missing'], 'surcharge_total' => ['missing'], 'payment_intent_id' => ['missing']];
    }
}
