<?php

namespace App\Http\Requests\Applications;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

class ListApplicationAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAccess', Application::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Filter the existing user assignment list to visible applications;
            // its absence preserves the original paginated endpoint behavior.
            'application_ids' => ['sometimes', 'array', 'max:100'],
            'application_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
