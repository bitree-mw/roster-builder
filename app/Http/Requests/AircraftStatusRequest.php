<?php

namespace App\Http\Requests;

use App\Models\Aircraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an availability change. Any status other than "available" needs a reason.
 */
class AircraftStatusRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(Aircraft::STATUSES))],
            'reason' => ['nullable', 'required_unless:status,available', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required_unless' => 'Give a reason when an aircraft is not available.'];
    }
}
