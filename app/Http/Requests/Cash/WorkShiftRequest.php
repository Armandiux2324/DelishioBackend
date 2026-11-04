<?php

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

class WorkShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['id' => ['missing'], 'employee_id' => ['missing'], 'restaurant_id' => ['missing'],
            'clock_in' => ['missing'], 'clock_out' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing']];
    }
}
