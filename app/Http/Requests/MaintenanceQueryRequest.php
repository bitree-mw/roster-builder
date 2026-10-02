<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional aircraft filter for the maintenance log.
 */
class MaintenanceQueryRequest extends FormRequest
{
    /**
     * Staff with read access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('read-operations') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['aircraft_id' => ['sometimes', 'integer', 'exists:aircraft,id'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
