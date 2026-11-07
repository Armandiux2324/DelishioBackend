<?php

namespace App\Http\Requests\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ReportDateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'restaurant_id' => ['missing'],
        ];
    }

    public function startDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('from'), config('app.timezone'))->startOfDay();
    }

    public function endDateExclusive(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('to'), config('app.timezone'))->startOfDay()->addDay();
    }
}
