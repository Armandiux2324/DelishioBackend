<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourtesyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['admin_user_id' => ['sometimes', 'required', 'integer'], 'pin' => ['required', 'string', 'regex:/\\A[0-9]{4,6}\\z/'],
            'order_id' => ['missing'], 'kind' => ['missing'], 'requested_by_employee_id' => ['missing'], 'authorized_by_employee_id' => ['missing'], 'work_shift_id' => ['missing'], 'created_at' => ['missing'], 'id' => ['missing'], 'restaurant_id' => ['missing'], 'order_item_id' => ['missing'], 'percentage' => ['missing'], 'amount' => ['missing'],
            'courtesy_percentage' => ['missing'], 'courtesy_daily_limit' => ['missing']];
    }
}
