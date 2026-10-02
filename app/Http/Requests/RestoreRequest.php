<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirm a restore: the token from the checked backup and the word RESTORE typed by the administrator,
 * because a restore replaces every operational record.
 */
class RestoreRequest extends FormRequest
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
        return ['token' => ['required', 'uuid'], 'confirmation' => ['required', 'in:RESTORE']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirmation.in' => 'Type RESTORE in capitals to confirm.'];
    }
}
