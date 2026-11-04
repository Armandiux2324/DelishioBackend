<?php

namespace App\Http\Requests\Order;

use App\Models\MenuItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class OrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $text = ['sometimes', 'bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
            if (strlen($value) > 65535) {
                $fail(__('El texto no debe exceder 65535 bytes.'));
            }
        }];

        return [
            'menu_item_id' => $this->isMethod('POST') ? ['bail', 'required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! MenuItem::whereKey($value)->where('active', true)->exists()) {
                    $fail(__('El elemento de menú seleccionado no está disponible.'));
                }
            }] : ['missing'],
            'quantity' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'filled', 'integer', 'min:1', 'max:2147483647'],
            'modifiers' => $text, 'kitchen_notes' => $text,
            'id' => ['missing'], 'order_id' => ['missing'], 'restaurant_id' => ['missing'], 'unit_price' => ['missing'],
            'station' => ['missing'], 'status' => ['missing'], 'cancelled' => ['missing'], 'cancel_reason' => ['missing'],
            'cancelled_by_employee_id' => ['missing'], 'cancel_authorized_by_employee_id' => ['missing'], 'created_at' => ['missing'],
        ];
    }
}
