<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a manual seat edit: the crew member to assign (null clears the seat) and, when the choice breaks
 * a rule, the reason for overriding it. Optional destination or exchange seats request atomic paired edits.
 * Whether a reason is needed or a destination matches is decided by AssignmentService.
 */
class AssignmentRequest extends FormRequest
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
            'crew_member_id' => ['present', 'nullable', 'integer', 'exists:crew_members,id'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'target_assignment_id' => ['sometimes', 'required', 'integer', 'exists:assignments,id'],
            'exchange_assignment_id' => ['sometimes', 'required', 'integer', 'exists:assignments,id', Rule::prohibitedIf($this->has('target_assignment_id'))],
            'expected_crew_member_id' => ['required_with:exchange_assignment_id', 'integer', 'exists:crew_members,id'],
        ];
    }
}
