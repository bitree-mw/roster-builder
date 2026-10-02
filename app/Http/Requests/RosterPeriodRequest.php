<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RosterPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    public function rules(): array
    {
        return ['month' => ['required', 'date_format:Y-m', 'after_or_equal:'.now()->subMonthsNoOverflow(6)->startOfMonth()->format('Y-m'), 'before_or_equal:'.now()->addMonthsNoOverflow(24)->startOfMonth()->format('Y-m')]];
    }
}
