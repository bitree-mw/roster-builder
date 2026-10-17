<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates splitting a captain seat: after_leg is the number of the leg (from 1, in trip order) after which
 * the captain changes. Whether that leg lies inside the seat's section is decided by AssignmentService.
 */
class AssignmentSplitRequest extends FormRequest
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
        return ['after_leg' => ['required', 'integer', 'min:1', 'max:200']];
    }
}
