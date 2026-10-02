<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Optional filters for the crew directory list.
 */
class CrewQueryRequest extends FormRequest
{
    /**
     * Staff with read access only.
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
        return ['rank' => ['sometimes', Rule::in(['CPT', 'FO', 'CC'])], 'base' => ['sometimes', 'exists:airports,code'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
