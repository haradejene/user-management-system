<?php

namespace App\Http\Requests\Users;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
