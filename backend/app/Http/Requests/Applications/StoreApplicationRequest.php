<?php

namespace App\Http\Requests\Applications;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Application::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge(['slug' => Str::slug((string) $this->input('slug'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:applications,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
