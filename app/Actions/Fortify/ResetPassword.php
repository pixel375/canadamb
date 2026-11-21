<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => Identity::passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => $input['password']])->save();
            Identity::revoke($user);
        });
    }
}
