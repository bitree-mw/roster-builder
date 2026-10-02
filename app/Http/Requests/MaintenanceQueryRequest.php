<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('read-operations') ?? false;
    }

    public function rules(): array
    {
        return ['aircraft_id' => ['sometimes', 'integer', 'exists:aircraft,id'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
