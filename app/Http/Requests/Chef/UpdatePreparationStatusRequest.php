<?php

namespace App\Http\Requests\Chef;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreparationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:in_preparation,ready'],
            'id' => ['missing'], 'order_id' => ['missing'], 'restaurant_id' => ['missing'], 'station' => ['missing'],
            'menu_item_id' => ['missing'], 'quantity' => ['missing'], 'unit_price' => ['missing'],
            'modifiers' => ['missing'], 'kitchen_notes' => ['missing'], 'cancelled' => ['missing'],
            'cancel_reason' => ['missing'], 'cancelled_by_employee_id' => ['missing'],
            'cancel_authorized_by_employee_id' => ['missing'], 'created_at' => ['missing']];
    }
}
