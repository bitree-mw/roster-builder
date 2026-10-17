<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates building or rebuilding a roster. The only input is route_changes: the fingerprint of the route
 * changes (disabled, enabled or edited flights) the scheduler reviewed and accepted in the roster window.
 * RosterBuilderService refuses a rebuild with pending route changes unless it matches the current set.
 */
class RosterBuildRequest extends FormRequest
{
    /**
     * Staff with write access to operations only (crew never build rosters).
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
        return ['route_changes' => ['nullable', 'string', 'regex:/^[0-9a-f]{40}$/']];
    }
}
