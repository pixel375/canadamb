<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/account/listings',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => ['login' => 'login', 'verification' => '6,1'],
    'views' => true,
    'features' => [Features::registration(), Features::resetPasswords(), Features::emailVerification()],
];
