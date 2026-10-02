<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Commit a previewed import by its preview token.
 */
class ImportCommitRequest extends FormRequest
{
    /**
     * Staff with write access.
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
        return ['token' => ['required', 'uuid']];
    }
}
