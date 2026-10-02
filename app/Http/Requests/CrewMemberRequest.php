<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a complete crew member payload, including ratings and the three optional expiry documents.
 */
class CrewMemberRequest extends FormRequest
{
    /**
     * Staff with write access only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    /**
     * Base must be a crew base; at most one document per kind.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:254'],
            'rank' => ['required', Rule::in(['CPT', 'FO', 'CC'])],
            'base_airport' => ['required', Rule::exists('airports', 'code')->where('is_base', true)],
            'active' => ['required', 'boolean'],
            'all_aircraft' => ['required', 'boolean'],
            'rating_ids' => ['present', 'array', 'max:100'],
            'rating_ids.*' => ['integer', 'distinct', 'exists:aircraft_types,id'],
            'documents' => ['present', 'array', 'max:3'],
            'documents.*' => ['array:kind,expires_on'],
            'documents.*.kind' => ['required', 'distinct', Rule::in(['licence', 'medical', 'recurrent'])],
            'documents.*.expires_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Cross-field rating rules, checked only once the basic rules pass: "all aircraft" is for cabin crew only,
     * and a crew member needs either "all aircraft" or at least one specific rating, never both.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->boolean('all_aircraft') && $this->input('rank') !== 'CC') {
                $validator->errors()->add('all_aircraft', 'Only cabin crew can be rated on all aircraft.');
            }
            if (! $this->boolean('all_aircraft') && count($this->input('rating_ids', [])) === 0) {
                $validator->errors()->add('rating_ids', 'Choose at least one aircraft rating.');
            }
            if ($this->boolean('all_aircraft') && count($this->input('rating_ids', [])) > 0) {
                $validator->errors()->add('rating_ids', 'Use either all aircraft or specific ratings.');
            }
        }];
    }
}
