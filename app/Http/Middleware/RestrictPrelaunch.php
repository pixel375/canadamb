<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RestrictPrelaunch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('network.prelaunch')) {
            return $next($request);
        }

        $headers = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'];
        if ($request->is('robots.txt')) {
            return response("User-agent: *\nDisallow: /\n", 200, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // Check the server's address without consulting a forwarded header.
        $peer = (string) $request->server('REMOTE_ADDR');
        if (! IpUtils::checkIp($peer, config('network.prelaunch_ips', []))) {
            return response('CanadaMB is in prelaunch testing.', 403, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($request->is('register')) {
            return response('', 404, $headers);
        }

        $response = $next($request);
        $response->headers->add($headers);

        return $response;
    }
}
