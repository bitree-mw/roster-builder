<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates emailing a published roster to crew. "only": "changed" limits the emails to the crew whose duties
 * changed when the roster was last published again (RosterPeriod::changed_crew); without it every crew member
 * with a seat is emailed.
 */
class RosterEmailRequest extends FormRequest
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
        return ['only' => ['nullable', 'string', 'in:changed']];
    }
}
