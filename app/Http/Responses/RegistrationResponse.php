<?php

namespace App\Http\Responses;

use App\Support\Identity;
use App\Support\NetworkUrl;
use App\Support\SignupLocations;
use Laravel\Fortify\Http\Responses\RegisterResponse;

class RegistrationResponse extends RegisterResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return parent::toResponse($request);
        }

        $site = $request->attributes->get('site');
        if (! $site->isNetwork()) {
            return redirect()->away(NetworkUrl::to(NetworkUrl::main(), '/email/verify'))->header('Cache-Control', 'private, no-store');
        }

        $session = $request->session();
        $central = NetworkUrl::main();
        if ($destination = Identity::takeCentralAuthorization($request)) {
            $session->put('url.intended', $destination);
        }
        $intended = NetworkUrl::pathOn($central, $session->get('url.intended'));
        $community = SignupLocations::enabled() ? null : Identity::registrationCommunity($request->input('community'));
        if ($intended === null) {
            $session->forget('url.intended');
        }
        if ($community && ! str_starts_with($intended ?? '', '/oauth/authorize?')) {
            $session->put('url.intended', NetworkUrl::to($community, '/auth/start'));
        }

        return config('network.prelaunch') || $community || str_starts_with($intended ?? '', '/oauth/authorize?')
            ? redirect('/email/verify')
            : parent::toResponse($request);
    }
}
