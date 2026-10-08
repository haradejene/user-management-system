<?php

namespace App\Http\Requests\Applications;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreOAuthClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('application')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => [
                'required',
                'string',
                'url',
                'max:2048',
                'distinct:strict',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (str_contains((string) $value, '*') || trim((string) $value) !== $value) {
                        $fail('Redirect URIs must be exact values without wildcards or surrounding whitespace.');
                    }
                    if (parse_url((string) $value, PHP_URL_FRAGMENT) !== null) {
                        $fail('Redirect URIs must not contain a fragment.');
                    }
                },
            ],
            'confidential' => ['sometimes', 'boolean'],
        ];
    }
}
