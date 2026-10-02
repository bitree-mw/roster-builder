<?php

namespace App\Http\Requests;

use App\Services\ImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A CSV file to preview for import: the kind of data, merge or replace, and (flights) whether leg times
 * in the file are base local or UTC. At most 2 MB.
 */
class ImportPreviewRequest extends FormRequest
{
    /**
     * Staff with write access.
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
            'kind' => ['required', Rule::in(array_keys(ImportService::KINDS))],
            'mode' => ['required', Rule::in(['merge', 'replace'])],
            'times' => ['nullable', Rule::in(['local', 'utc'])],
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['file.mimes' => 'Choose a .csv file (comma, semicolon or tab separated).', 'file.max' => 'The file must be 2 MB or smaller.'];
    }
}
