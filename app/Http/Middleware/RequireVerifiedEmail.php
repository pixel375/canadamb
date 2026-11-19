<?php

namespace App\Http\Middleware;

use App\Support\NetworkUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return $next($request);
        }
        abort_if($request->expectsJson() || $request->is('api/*'), 403, 'Verify your email before continuing.');

        return redirect()->guest(NetworkUrl::to(NetworkUrl::main(), '/email/verify'));
    }
}
