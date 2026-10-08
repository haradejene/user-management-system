<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passport\Passport;

class OAuthClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resource = [
            'id' => $this->id,
            'name' => $this->name,
            'application_id' => $this->application?->public_id,
            'redirect_uris' => $this->redirect_uris,
            'confidential' => $this->confidential(),
            'grant_types' => $this->grant_types,
            'revoked' => (bool) $this->revoked,
            'pkce_required' => $this->hasGrantType('authorization_code'),
            'pkce_method' => $this->hasGrantType('authorization_code') ? 'S256' : null,
            'allowed_scopes' => Passport::scopeIds(),
            'issuer' => config('oidc.issuer') ?? config('app.url'),
            'discovery_url' => rtrim(config('oidc.issuer') ?? config('app.url'), '/').'/.well-known/openid-configuration',
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        return $resource;
    }
}
