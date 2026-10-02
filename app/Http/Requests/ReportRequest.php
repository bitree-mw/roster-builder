<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Report filters: a base-local date range of at most 366 days and, for the hours report, the crew group.
 */
class ReportRequest extends FormRequest
{
    /**
     * Staff with read access.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('read-operations') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$this->rangeLimit()],
            'group' => ['nullable', Rule::in(['pilots', 'cabin'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['to.before_or_equal' => 'Choose a period of at most 366 days.'];
    }

    /** The latest allowed end date: 365 days after the start. */
    private function rangeLimit(): string
    {
        $from = strtotime((string) $this->input('from'));

        return $from ? date('Y-m-d', $from + 365 * 86400) : '1970-01-01';
    }
}
