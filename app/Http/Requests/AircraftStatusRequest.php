<?php

namespace App\Http\Requests;

use App\Models\Aircraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AircraftStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(Aircraft::STATUSES))],
            'reason' => ['nullable', 'required_unless:status,available', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required_unless' => 'Give a reason when an aircraft is not available.'];
    }
}
