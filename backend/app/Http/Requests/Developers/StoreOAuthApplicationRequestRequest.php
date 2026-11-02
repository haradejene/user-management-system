<?php

namespace App\Http\Requests\Developers;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreOAuthApplicationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('developer_email')) {
            $this->merge([
                'developer_email' => mb_strtolower(trim((string) $this->input('developer_email'))),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'developer_name' => ['required', 'string', 'min:2', 'max:255'],
            'developer_email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'company_id' => ['nullable', 'string', 'exists:companies,public_id'],
            'organization' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
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
            'requested_scopes' => ['sometimes', 'array'],
            'requested_scopes.*' => ['string', 'max:50'],
        ];
    }
}
