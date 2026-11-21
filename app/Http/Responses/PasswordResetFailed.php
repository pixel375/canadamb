<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;

class PasswordResetFailed implements FailedPasswordResetResponse
{
    public function toResponse($request)
    {
        throw ValidationException::withMessages(['email' => 'The reset link is invalid or has expired. Request a new link.']);
    }
}
