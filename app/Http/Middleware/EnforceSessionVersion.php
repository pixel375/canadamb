<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::guard('web')->setRememberDuration(30 * 24 * 60);
        $host = $request->session()->get('session_host');
        $user = $request->user();
        if ($user && (($host && $host !== $request->getHost())
            || (int) $request->session()->get('session_version', 0) !== (int) $user->session_version)) {
            Auth::logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
