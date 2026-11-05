<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class LinkOrderCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['qr_code' => ['required', 'string', 'uuid'], 'customer_id' => ['missing'], 'restaurant_id' => ['missing'],
            'order_id' => ['missing'], 'points_balance' => ['missing']];
    }
}
