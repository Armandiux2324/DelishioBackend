<?php

namespace App\Http\Requests\Chef;

use Illuminate\Foundation\Http\FormRequest;

class ListQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'string', 'in:pending,in_preparation,ready'],
            'station' => ['missing'], 'restaurant_id' => ['missing'], 'employee_id' => ['missing']];
    }
}
