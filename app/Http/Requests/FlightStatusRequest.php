<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FlightStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-operations') ?? false;
    }

    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }
}
