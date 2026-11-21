<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use App\Support\Identity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateUser implements CreatesNewUsers
{
    public function create(array $input): User
    {
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => Identity::passwordRules(),
        ])->validate();

        if (User::whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages(['email' => 'Unable to register with this email. Try signing in or recovering your account.']);
        }

        try {
            return DB::transaction(function () use ($data) {
                $user = User::create($data);
                if (config('network.prelaunch')) {
                    Audit::record('identity.prelaunch.registered', $user->id);
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Unable to register with this email. Try signing in or recovering your account.']);
        }
    }
}
