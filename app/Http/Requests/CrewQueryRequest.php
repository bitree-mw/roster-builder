<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrewQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('read-operations') ?? false;
    }

    public function rules(): array
    {
        return ['rank' => ['sometimes', Rule::in(['CPT', 'FO', 'CC'])], 'base' => ['sometimes', 'exists:airports,code'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
