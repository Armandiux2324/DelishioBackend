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

    public function startDate(?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('from'), $timezone ?? config('app.timezone'))->startOfDay();
    }

    public function endDateExclusive(?string $timezone = null): CarbonImmutable
    {
        // Advance the calendar date before resolving midnight in a zone where midnight can be skipped.
        $nextDate = CarbonImmutable::parse($this->validated('to'), 'UTC')->addDay()->toDateString();

        return CarbonImmutable::parse($nextDate, $timezone ?? config('app.timezone'))->startOfDay();
    }
}
