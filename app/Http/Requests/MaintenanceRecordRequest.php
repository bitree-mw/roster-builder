<?php

namespace App\Http\Requests;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a maintenance record. The hours ordering rule lives in MaintenanceService because either value
 * may be omitted.
 */
class MaintenanceRecordRequest extends FormRequest
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
            'aircraft_id' => ['required', 'integer', 'exists:aircraft,id'],
            'kind' => ['required', Rule::in(array_keys(MaintenanceRecord::KINDS))],
            'title' => ['required', 'string', 'max:150'],
            'performed_on' => ['required', 'date_format:Y-m-d'],
            'airframe_hours_at' => ['nullable', 'numeric', 'between:0,999999'],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after:performed_on'],
            'next_due_hours' => ['nullable', 'numeric', 'between:0,999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['next_due_on.after' => 'The next due date must be after the date the work was performed.'];
    }
}
