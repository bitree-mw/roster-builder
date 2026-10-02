<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a manual seat edit: the crew member to assign (null clears the seat) and, when the choice breaks
 * a rule, the reason for overriding it. Whether a reason is needed is decided by AssignmentService.
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'crew_member_id' => ['present', 'nullable', 'integer', 'exists:crew_members,id'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
