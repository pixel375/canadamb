<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ProtectIdentityRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('network.prelaunch') && $request->is('reset-password', 'reset-password/*', 'email/verification-notification')) {
            return redirect($request->is('email/*') ? '/email/verify' : '/forgot-password');
        }

        if (! $request->isMethod('POST') || ! $request->is('register', 'login', 'forgot-password', 'reset-password')) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, function (Request $request) use ($next) {
            if (config('network.prelaunch') && $request->is('forgot-password')) {
                return redirect('/forgot-password');
            }
            // Fortify normalizes the email before the registration action validates it.
            $request->validate(['email' => 'sometimes|required|string|max:254']);
            if ($request->is('login')) {
                $request->mergeIfMissing(['remember' => true]);
                $request->validate(['remember' => 'boolean']);
            }
            if ($request->is('reset-password')) {
                $request->validate(['token' => 'required|string|max:255']);
            }

            return $next($request);
        }, 'identity-requests');
    }
}
