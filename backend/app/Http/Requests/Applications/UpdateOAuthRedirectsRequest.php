<?php

namespace App\Http\Requests\Applications;

class UpdateOAuthRedirectsRequest extends StoreOAuthClientRequest
{
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip(['redirect_uris', 'redirect_uris.*'])) + [
            'updated_at' => ['required', 'date'],
        ];
    }
}
