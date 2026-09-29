<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        ];

        return $resource;
    }
}
