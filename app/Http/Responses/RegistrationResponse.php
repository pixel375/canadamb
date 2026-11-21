<?php

namespace App\Http\Responses;

use App\Support\Identity;
use App\Support\NetworkUrl;
use Laravel\Fortify\Http\Responses\RegisterResponse;

class RegistrationResponse extends RegisterResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return parent::toResponse($request);
        }

        $session = $request->session();
        $central = NetworkUrl::main();
        if ($destination = Identity::takeCentralAuthorization($request)) {
            $session->put('url.intended', $destination);
        }
        $intended = NetworkUrl::pathOn($central, $session->get('url.intended'));
        $community = Identity::registrationCommunity($request->input('community'));
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
