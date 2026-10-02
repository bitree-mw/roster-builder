<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Calendar download for one crew member. Staff must say whose calendar they want; crew accounts always get
 * their own, so any crew_member_id they send is ignored.
 */
class RosterCalendarRequest extends FormRequest
{
    /**
     * Staff, or crew accounts linked to a crew member.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('read-rosters') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->user()->isStaff() ? ['crew_member_id' => ['required', 'integer', 'exists:crew_members,id']] : [];
    }
}
