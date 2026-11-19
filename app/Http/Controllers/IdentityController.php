<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Identity;
use App\Support\NetworkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class IdentityController extends Controller
{
    public function start(Request $request)
    {
        $site = $request->attributes->get('site');
        if ($site->isNetwork()) {
            return redirect('/login');
        }
        abort_unless(config('network.sso_enabled') && $site->domain->oauth_client_id, 503, 'Community sign-in is unavailable.');
        if ($request->user()) {
            return redirect('/account/listings');
        }
        $verifier = Str::random(64);
        $state = Str::random(64);
        $intended = $request->session()->pull('url.intended');
        $path = is_string($intended) && str_starts_with($intended, NetworkUrl::to($site))
            ? '/'.substr($intended, strlen(NetworkUrl::to($site))) : '/account/listings';
        $request->session()->put('community_oauth', ['state' => $state, 'verifier' => $verifier, 'at' => now()->timestamp, 'path' => $path]);

        return redirect()->away(NetworkUrl::to(NetworkUrl::main(), '/oauth/authorize?'.http_build_query([
            'client_id' => $site->domain->oauth_client_id,
            'redirect_uri' => NetworkUrl::to($site, '/auth/callback'),
            'response_type' => 'code',
            'scope' => 'community-sign-in',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ])))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, AccessTokenController $tokens, ResourceServer $server)
    {
        $site = $request->attributes->get('site');
        abort_if($site->isNetwork() || ! config('network.sso_enabled') || ! $site->domain->oauth_client_id, 404);
        $data = $request->validate(['code' => 'required|string|max:8192', 'state' => 'required|string|size:64']);
        $flow = $request->session()->pull('community_oauth');
        abort_unless($flow && $flow['at'] >= now()->subMinutes(10)->timestamp && hash_equals($flow['state'], $data['state']), 403);

        $factory = new PsrHttpFactory;
        $issuer = NetworkUrl::to(NetworkUrl::main(), '/oauth/token');
        $exchange = Request::create($issuer, 'POST', [
            'grant_type' => 'authorization_code', 'client_id' => $site->domain->oauth_client_id,
            'redirect_uri' => NetworkUrl::to($site, '/auth/callback'), 'code' => $data['code'],
            'code_verifier' => $flow['verifier'],
        ]);

        // The provider and its client are in the same application. Use Passport's server directly.
        $result = $tokens->issueToken($factory->createRequest($exchange), $factory->createResponse(new Response));
        abort_unless($result->isSuccessful(), 403, 'The sign-in request expired. Start again.');
        $token = json_decode($result->getContent(), true, flags: JSON_THROW_ON_ERROR);
        try {
            $validated = $server->validateAuthenticatedRequest($factory->createRequest(Request::create($issuer, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token['access_token'],
            ])));
        } catch (OAuthServerException) {
            abort(403);
        }

        $id = $validated->getAttribute('oauth_access_token_id');
        $user = DB::transaction(function () use ($validated, $site, $id) {
            abort_unless($validated->getAttribute('oauth_client_id') === $site->domain->oauth_client_id
                && $validated->getAttribute('oauth_scopes') === ['community-sign-in'], 403);
            $user = User::whereKey($validated->getAttribute('oauth_user_id'))->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('oauth_access_tokens')->where('id', $id)->where('revoked', false)->update(['revoked' => true]), 403);
            DB::table('oauth_refresh_tokens')->where('access_token_id', $id)->update(['revoked' => true]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->away(NetworkUrl::to($site, $flow['path']))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            Identity::revoke($request->user());
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
