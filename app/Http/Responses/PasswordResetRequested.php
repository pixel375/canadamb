<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class PasswordResetRequested implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        $message = 'If an account matches that email, you can use the reset link it receives.';

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect('/forgot-password')->with('status', $message);
    }
}
