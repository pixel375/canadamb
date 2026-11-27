<?php

namespace App\Http\Middleware;

use App\Support\NetworkSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class RestrictPrelaunch
{
    private const COOKIE = '__Host-canadamb-preview';

    private const LIFETIME = 60 * 60 * 24 * 30;

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

        $hash = config('network.prelaunch_password_hash');
        if (app()->isProduction() || $hash !== null) {
            if (self::validHash($hash) === false || empty(config('app.key'))) {
                return response('CanadaMB is in prelaunch testing.', 403, $headers);
            }
            if ($request->is('prelaunch-access') && $request->isMethod('POST')) {
                return $this->unlock($request, $hash, $headers);
            }
            if ($this->hasAccess($request, $hash) === false) {
                return $this->locked($request, $headers);
            }
        } elseif (IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), config('network.prelaunch_ips', [])) === false) {
            // Legacy non-production fixture mode only; production never bypasses the password by IP.
            return response('CanadaMB is in prelaunch testing.', 403, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($request->is('register') && ! app(NetworkSettings::class)->registrationAllowed()) {
            return response('', 404, $headers);
        }

        $response = $next($request);
        $response->headers->add($headers);

        return $response;
    }

    public static function validHash(mixed $hash): bool
    {
        return is_string($hash) && password_get_info($hash)['algoName'] === 'bcrypt';
    }

    private function signature(Request $request, string $hash, int $expiry): string
    {
        return hash_hmac('sha256', 'canadamb-preview|'.$request->getHost().'|'.$hash.'|'.$expiry, (string) config('app.key'));
    }

    private function hasAccess(Request $request, string $hash): bool
    {
        $token = $request->cookies->get(self::COOKIE);
        if (is_string($token) === false || preg_match('/^([0-9]{1,12})\.([a-f0-9]{64})$/D', $token, $parts) !== 1) {
            return false;
        }
        $expiry = (int) $parts[1];

        return $expiry > time() && $expiry <= time() + self::LIFETIME
            && hash_equals($this->signature($request, $hash, $expiry), $parts[2]);
    }

    private function unlock(Request $request, string $hash, array $headers): Response
    {
        $key = 'prelaunch-password:'.hash('sha256', (string) $request->server('REMOTE_ADDR'));
        $password = $request->input('preview_password');
        $target = $this->safeTarget($request->input('return_to'));
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->locked($request, $headers, 'Too many attempts. Try again in a minute.', $target, 429);
        }
        RateLimiter::hit($key, 60);
        if (is_string($password) === false || strlen($password) > 72 || password_verify($password, $hash) === false) {
            return $this->locked($request, $headers, 'Incorrect password.', $target);
        }
        RateLimiter::clear($key);
        $expiry = time() + self::LIFETIME;
        $response = new RedirectResponse($target, 303, $headers);
        $response->headers->setCookie(Cookie::create(self::COOKIE, $expiry.'.'.$this->signature($request, $hash, $expiry), $expiry, '/', null, true, true, false, Cookie::SAMESITE_LAX));

        return $response;
    }

    private function safeTarget(mixed $target): string
    {
        if (is_string($target) === false || strlen($target) > 4096 || str_starts_with($target, '/') === false
            || str_starts_with($target, '//') || str_contains($target, chr(92))
            || preg_match('/[\x00-\x20\x7f]/', $target) || preg_match('~^/(?:%2f|%5c)~i', $target)
            || str_starts_with($target, '/prelaunch-access')) {
            return '/';
        }

        return $target;
    }

    private function locked(Request $request, array $headers, string $message = '', ?string $target = null, int $status = 403): Response
    {
        if ($request->is('api/*') || $request->expectsJson() || ($request->isMethodSafe() === false && $request->is('prelaunch-access') === false)) {
            return response('CanadaMB is in prelaunch testing.', $status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response()->view('prelaunch-access', [
            'message' => $message,
            'target' => $target ?? $this->safeTarget($request->getRequestUri()),
        ], $status, $headers + [
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
