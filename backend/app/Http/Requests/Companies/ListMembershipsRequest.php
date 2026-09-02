<?php

namespace App\Http\Requests\Companies;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class ListMembershipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company
            ? ($this->user()?->can('manageMembers', $company) ?? false)
            : ($this->user()?->can('viewAny', Company::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
