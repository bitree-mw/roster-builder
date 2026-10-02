<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Roster PDF options: the full grid or one page per crew member, optionally for selected crew only.
 * Crew accounts always get their own page, so their options are ignored.
 */
class RosterPdfRequest extends FormRequest
{
    /**
     * Staff, or crew accounts linked to a crew member.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('read-rosters') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->user()->isStaff() ? [
            'layout' => ['sometimes', Rule::in(['grid', 'crew'])],
            'crew_member_ids' => ['sometimes', 'array', 'max:200'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
        ] : [];
    }
}
