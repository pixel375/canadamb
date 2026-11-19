<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

class CommunityOAuthClient extends Client
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return count($scopes) === 1 && $scopes[0]->id === 'community-sign-in'
            && Domain::where('oauth_client_id', $this->id)->whereHas('site', fn ($site) => $site->where('status', 'active'))->exists();
    }
}
