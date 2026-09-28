<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->resource instanceof User
            ? $this->resource->profile
            : $this->resource;
        $user = $this->resource instanceof User
            ? $this->resource
            : $this->resource?->user;

        return [
            'user_id' => $user?->public_id,
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'phone' => $profile?->phone,
            'photo' => $profile?->profile_photo,
            'created_at' => $profile?->created_at?->toISOString(),
            'updated_at' => $profile?->updated_at?->toISOString(),
        ];
    }
}
