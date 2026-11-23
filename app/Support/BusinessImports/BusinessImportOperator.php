<?php

namespace App\Support\BusinessImports;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final class BusinessImportOperator
{
    public static function authenticate(Command $command): User
    {
        $id = filter_var($command->option('actor') ?? $command->ask('Operator account ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('A verified import operator account is required.');
        }
        $key = 'business-import-console:'.$id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new RuntimeException('Too many authentication attempts. Try again later.');
        }
        $operator = User::find($id);
        $password = $command->secret('Operator password', fallback: false);
        if ($operator === null || ! is_string($password) || ! Hash::check($password, $operator->password)) {
            RateLimiter::hit($key, 300);
            throw new RuntimeException('Could not authenticate the import operator.');
        }
        BusinessSources::authorize($operator);
        RateLimiter::clear($key);

        return $operator;
    }

    public static function id(mixed $argument): int
    {
        $id = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Supply a positive numeric ID.');
        }

        return $id;
    }
}
