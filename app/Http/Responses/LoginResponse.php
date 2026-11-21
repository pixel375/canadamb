<?php

namespace App\Http\Responses;

use App\Support\Identity;
use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;

class LoginResponse extends FortifyLoginResponse
{
    public function toResponse($request)
    {
        if (! $request->wantsJson() && ($destination = Identity::takeCentralAuthorization($request))) {
            return redirect()->away($destination);
        }

        return parent::toResponse($request);
    }
}
