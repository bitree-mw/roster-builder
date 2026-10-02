<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional month filter for roster periods. Staff and crew with a linked profile may read.
 */
class RosterQueryRequest extends FormRequest
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
        return ['month' => ['sometimes', 'date_format:Y-m']];
    }
}
