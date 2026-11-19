<?php

namespace App\Support;

use App\Models\CommunityOAuthClient;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

final class Identity
{
    public static function provision(Site $site): void
    {
        DB::transaction(function () use ($site) {
            $domain = $site->domain()->lockForUpdate()->firstOrFail();
            $callback = NetworkUrl::to($site, '/auth/callback');
            $current = CommunityOAuthClient::find($domain->oauth_client_id);
            if ($current && ! $current->revoked && $current->redirect_uris === [$callback]) {
                return;
            }
            if ($current) {
                $current->forceFill(['revoked' => true])->save();
            }
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($site->name, [$callback], confidential: false);
            $domain->forceFill(['oauth_client_id' => $client->id])->save();
        });
    }

    public static function revoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->increment('session_version', 1, ['remember_token' => Str::random(60)]);
            DB::table('oauth_auth_codes')->where('user_id', $user->id)->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->where('user_id', $user->id)->update(['revoked' => true]);
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id',
                DB::table('oauth_access_tokens')->select('id')->where('user_id', $user->id))->update(['revoked' => true]);
        });
    }
}
