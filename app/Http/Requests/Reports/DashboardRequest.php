<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'required', 'in:today,yesterday,week', 'prohibits:from,to'],
            'from' => ['required_with:to', 'filled', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'filled', 'date_format:Y-m-d', 'after_or_equal:from'],
            'restaurant_id' => ['missing'],
        ];
    }
}
