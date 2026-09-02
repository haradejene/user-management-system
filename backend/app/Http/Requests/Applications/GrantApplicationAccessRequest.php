<?php

namespace App\Http\Requests\Applications;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

class GrantApplicationAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAccess', Application::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'application_id' => ['required', 'uuid', 'exists:applications,public_id'],
        ];
    }
}
