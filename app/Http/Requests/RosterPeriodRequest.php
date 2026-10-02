<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates creating a roster period: a month from six months back to 24 months ahead.
 */
class RosterPeriodRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['month' => ['required', 'date_format:Y-m', 'after_or_equal:'.now()->subMonthsNoOverflow(6)->startOfMonth()->format('Y-m'), 'before_or_equal:'.now()->addMonthsNoOverflow(24)->startOfMonth()->format('Y-m')]];
    }
}
