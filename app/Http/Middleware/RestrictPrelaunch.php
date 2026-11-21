<?php

namespace App\Http\Middleware;

use App\Support\NetworkSettings;
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

        // Chrome's WebAPK service may request the manifest and icons from an
        // external server. Expose only public PWA installation metadata; never
        // exempt HTML, login, messages, admin, APIs, or the service worker.
        $publicInstallMetadata = $request->is('app.webmanifest', 'pwa/icon/180.png', 'pwa/icon/192.png', 'pwa/icon/512.png')
            && $request->isMethodSafe();
        if ($publicInstallMetadata) {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'public, max-age=300');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        // Check the server's address without consulting a forwarded header.
        $peer = (string) $request->server('REMOTE_ADDR');
        if (! IpUtils::checkIp($peer, config('network.prelaunch_ips', []))) {
            return response('CanadaMB is in prelaunch testing.', 403, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($request->is('register') && ! app(NetworkSettings::class)->registrationAllowed()) {
            return response('', 404, $headers);
        }

        $response = $next($request);
        $response->headers->add($headers);

        return $response;
    }
}
