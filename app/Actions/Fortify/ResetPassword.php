<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)],
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => $input['password']])->save();
            Identity::revoke($user);
        });
    }
}
