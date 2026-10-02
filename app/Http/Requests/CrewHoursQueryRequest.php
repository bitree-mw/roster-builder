<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Group filter for the staff hours report: pilots (CPT and FO) or cabin crew (CC).
 */
class CrewHoursQueryRequest extends FormRequest
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
        return ['group' => ['sometimes', Rule::in(['pilots', 'cabin'])]];
    }
}
