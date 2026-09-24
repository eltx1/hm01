<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ReportPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware retains workspace and report permissions.
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => $this->input('from') ?: now()->startOfMonth()->toDateString(),
            'to' => $this->input('to') ?: now()->toDateString(),
        ]);
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function ($attribute, $value, $fail): void {
                if (is_string($this->input('from')) && is_string($value)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->input('from'))
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                    try {
                        if (CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($value)) > 366) {
                            $fail('Choose a reporting period of up to one year.');
                        }
                    } catch (\Throwable) { /* Date-format validation supplies the error. */ }
                }
            }],
        ];
    }
}
