<?php

namespace App\Http\Requests\Promotion;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Promotion;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['time_start', 'time_end'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && preg_match('/\\A[0-9]{2}:[0-9]{2}\\z/', $value)) {
                $this->merge([$field => $value.':00']);
            }
        }
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'filled', 'string', 'max:255'],
            'condition_type' => [$presence, 'filled', 'in:min_amount,product_quantity,category_discount'],
            'condition_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'discount_type' => [$presence, 'filled', 'in:percentage,fixed_amount'],
            'discount_value' => [$presence, 'filled', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'days_of_week' => ['sometimes', 'bail', 'nullable', 'string', 'max:255',
                'regex:/\\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:,(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun))*\\z/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $days = explode(',', $value);
                    if (count($days) !== count(array_unique($days))) {
                        $fail(__('Los días de aplicación no deben repetirse.'));
                    }
                }],
            'time_start' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'time_end' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'valid_from' => [$presence, 'filled', 'date_format:Y-m-d'],
            'valid_to' => [$presence, 'filled', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
            'menu_item_ids' => ['sometimes', 'array', 'list'],
            'menu_item_ids.*' => ['bail', 'required', 'integer', 'distinct', function (string $attribute, mixed $value, Closure $fail) {
                if (! MenuItem::whereKey($value)->exists()) {
                    $fail(__('El elemento de menú seleccionado no está disponible.'));
                }
            }],
            'category_ids' => ['sometimes', 'array', 'list'],
            'category_ids.*' => ['bail', 'required', 'integer', 'distinct', function (string $attribute, mixed $value, Closure $fail) {
                if (! Category::whereKey($value)->exists()) {
                    $fail(__('La categoría seleccionada no está disponible.'));
                }
            }],
            'id' => ['missing'], 'restaurant_id' => ['missing'], 'deleted_at' => ['missing'],
            'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                $this->validateState($this->route('promotion'), $validator->validated());
            }
        }];
    }

    public function validateState(?Promotion $promotion, array $data): void
    {
        $state = array_replace($promotion?->getAttributes() ?? [], $data);
        $state['category_ids'] = $data['category_ids'] ?? $promotion?->categories()->pluck('categories.id')->all() ?? [];
        if (($state['condition_type'] ?? null) === 'category_discount' && ! array_key_exists('condition_value', $data)) {
            $state['condition_value'] = null;
        }
        $condition = $state['condition_type'] ?? null;
        ValidatorFacade::make($state, [
            'condition_type' => ['required', 'in:min_amount,product_quantity,category_discount'],
            'condition_value' => $condition === 'category_discount' ? ['prohibited'] : [
                'required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2',
                ...($condition === 'product_quantity' ? ['multiple_of:1'] : []),
            ],
            'discount_type' => ['required', 'in:percentage,fixed_amount'],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:'.(($state['discount_type'] ?? null) === 'percentage' ? '100' : '99999999.99'), 'decimal:0,2'],
            'category_ids' => $condition === 'category_discount' ? ['required', 'array', 'min:1'] : ['array'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'time_start' => ['nullable', 'required_with:time_end', 'date_format:H:i:s'],
            'time_end' => ['nullable', 'required_with:time_start', 'date_format:H:i:s', 'different:time_start'],
        ])->validate();
    }
}
