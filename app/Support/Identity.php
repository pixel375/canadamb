<?php

namespace App\Support;

use App\Models\CommunityOAuthClient;
use App\Models\Site;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\ClientRepository;

final class Identity
{
    public static function rememberCentralAuthorization(Request $request): ?string
    {
        $path = NetworkUrl::pathOn(NetworkUrl::main(), $request->fullUrl());
        if (! str_starts_with($path ?? '', '/oauth/authorize?')) {
            return null;
        }
        $ticket = Str::random(40);
        $attempts = array_filter($request->session()->get('central_oauth', []), fn ($entry) => $entry['expires'] >= now()->timestamp);
        $attempts[$ticket] = ['path' => $path, 'expires' => now()->addMinutes(10)->timestamp];
        $request->session()->put('central_oauth', array_slice($attempts, -5, null, true));

        return $ticket;
    }

    public static function takeCentralAuthorization(Request $request): ?string
    {
        $ticket = $request->input('continuation');
        if (! is_string($ticket) || ! preg_match('/^[a-zA-Z0-9]{40}$/D', $ticket)) {
            return null;
        }
        $attempt = $request->session()->pull('central_oauth.'.$ticket);

        return $attempt && $attempt['expires'] >= now()->timestamp ? NetworkUrl::to(NetworkUrl::main(), $attempt['path']) : null;
    }

    public static function passwordRules(): array
    {
        return ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password may not exceed 72 bytes.');
                }
            }];
    }

    public static function registrationCommunity(mixed $id): ?Site
    {
        if (! is_scalar($id) || ! filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            return null;
        }
        $site = Site::with('domain')->whereNotNull('location_id')->where('status', 'active')->find($id);

        return $site && self::communityClient($site) ? $site : null;
    }

    public static function communityClient(Site $site): ?CommunityOAuthClient
    {
        if (! config('network.sso_enabled') || ! $site->domain?->oauth_client_id) {
            return null;
        }
        $client = CommunityOAuthClient::find($site->domain->oauth_client_id);

        return $client && ! $client->revoked && $client->redirect_uris === [NetworkUrl::to($site, '/auth/callback')] ? $client : null;
    }

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
