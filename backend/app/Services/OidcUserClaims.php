<?php

namespace App\Services;

use App\Models\User;

class OidcUserClaims
{
    /** @param list<string> $scopes
     * @return array<string, string|bool>
     */
    public function forUser(User $user, array $scopes): array
    {
        $claims = ['sub' => $user->public_id];
        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }
        if (in_array('profile', $scopes, true)) {
            $profile = [
                'name' => $user->name,
                'given_name' => $user->profile?->first_name,
                'family_name' => $user->profile?->last_name,
                'picture' => $user->profile?->profile_photo,
            ];
            // OIDC picture is a public image URL, never an internal storage path.
            if (! is_string($profile['picture']) || ! filter_var($profile['picture'], FILTER_VALIDATE_URL)
                || ! in_array(parse_url($profile['picture'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                unset($profile['picture']);
            }
            foreach ($profile as $claim => $value) {
                if (is_string($value) && $value !== '') {
                    $claims[$claim] = $value;
                }
            }
        }

        return $claims;
    }
}
