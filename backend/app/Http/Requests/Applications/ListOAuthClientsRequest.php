<?php

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

class ListOAuthClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('application')) ?? false;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
