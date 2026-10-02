<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A backup file to check before restoring (JSON, at most 50 MB). Administrators only.
 */
class BackupUploadRequest extends FormRequest
{
    /**
     * Administrators with write access.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-backups') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:51200', 'mimetypes:application/json,text/plain']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['file.mimetypes' => 'Choose a .json backup file downloaded from this system.'];
    }
}
