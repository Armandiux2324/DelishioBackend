<?php

namespace App\Http\Requests\Restaurant;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantConfigurationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen($value) > 65535) {
                    $fail(__('La descripción no debe exceder 65535 bytes.'));
                }
            }],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            'schedule' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'in:USD,MXN'],
            'loyalty_enabled' => ['sometimes', 'required', 'boolean'],
            'loyalty_factor' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999.9999', 'decimal:0,4'],
            'loyalty_redeem_value' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999.9999', 'decimal:0,4'],
            'discount_limit_per_shift' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'courtesy_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'courtesy_daily_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'dual_pricing_enabled' => ['sometimes', 'required', 'boolean'],
            'dual_pricing_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
            'id' => ['missing'],
            'stripe_account_id' => ['missing'],
            'restaurant_id' => ['missing'],
            'status' => ['missing'],
            'created_by_user_id' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
            'deleted_at' => ['missing'],
        ];
    }
}
