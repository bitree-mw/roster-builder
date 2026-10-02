<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RosterQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('read-rosters') ?? false;
    }

    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m']];
    }
}
