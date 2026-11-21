<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Identity;
use App\Support\NetworkUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Exceptions\OAuthServerException as PassportException;
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
        $client = Identity::communityClient($site);
        if (! $client) {
            return $this->signInError(503);
        }
        $path = NetworkUrl::pathOn($site, $request->session()->pull('url.intended'));
        if (! $path || Str::is(['auth', 'auth/*', 'login', 'register'], ltrim(parse_url($path, PHP_URL_PATH), '/'))) {
            $path = '/account/listings';
        }
        if ($request->user()) {
            return redirect()->away(NetworkUrl::to($site, $path));
        }
        $verifier = Str::random(64);
        $state = Str::random(64);
        $flows = array_filter($request->session()->get('community_oauth', []), fn ($flow) => is_array($flow) && ($flow['at'] ?? 0) >= now()->subMinutes(10)->timestamp);
        $flows[$state] = ['verifier' => $verifier, 'at' => now()->timestamp, 'path' => $path, 'client' => $client->id];
        $request->session()->put('community_oauth', array_slice($flows, -5, null, true));

        return redirect()->away(NetworkUrl::to(NetworkUrl::main(), '/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => NetworkUrl::to($site, '/auth/callback'),
            'response_type' => 'code',
            'scope' => 'community-sign-in',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ])))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_if($site->isNetwork(), 404);
        $client = Identity::communityClient($site);
        if (! $client) {
            return $this->signInError(503);
        }
        $query = $request->query->all();
        $state = $query['state'] ?? null;
        if (! is_string($state) || ! preg_match('/^[a-zA-Z0-9]{64}$/D', $state)) {
            return $this->signInError();
        }
        $flow = $request->session()->pull('community_oauth.'.$state);
        if (! $flow || $flow['at'] < now()->subMinutes(10)->timestamp || $flow['client'] !== $client->id) {
            return $this->signInError();
        }
        $code = $query['code'] ?? null;
        if (isset($query['error']) || ! is_string($code) || $code === '' || strlen($code) > 8192) {
            return $this->signInError();
        }

        $factory = new PsrHttpFactory;
        $issuer = NetworkUrl::to(NetworkUrl::main(), '/oauth/token');
        $exchange = Request::create($issuer, 'POST', [
            'grant_type' => 'authorization_code', 'client_id' => $site->domain->oauth_client_id,
            'redirect_uri' => NetworkUrl::to($site, '/auth/callback'), 'code' => $code,
            'code_verifier' => $flow['verifier'],
        ]);

        // The provider and its client are in the same application. Use Passport's server directly.
        try {
            $result = app(AccessTokenController::class)->issueToken($factory->createRequest($exchange), $factory->createResponse(new Response));
        } catch (PassportException) {
            return $this->signInError();
        }
        if (! $result->isSuccessful()) {
            return $this->signInError();
        }
        $token = json_decode($result->getContent(), true, flags: JSON_THROW_ON_ERROR);
        try {
            $validated = app(ResourceServer::class)->validateAuthenticatedRequest($factory->createRequest(Request::create($issuer, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token['access_token'],
            ])));
        } catch (OAuthServerException) {
            return $this->signInError();
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

    private function signInError(int $status = 403): Response
    {
        return response()->view('auth.community-error', ['unavailable' => $status === 503], $status)
            ->header('Referrer-Policy', 'no-referrer');
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
