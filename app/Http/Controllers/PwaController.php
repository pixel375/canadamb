<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\NetworkSettings;
use App\Support\PushDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function manifest(Request $request, NetworkSettings $settings): JsonResponse
    {
        $site = $this->enabledSite($request, $settings);
        $darkPalette = $settings->forSite($site)['palette']['dark'] ?? [];

        $manifest = [
            'id' => '/',
            'name' => $site->name,
            'short_name' => $site->name,
            'description' => $site->description,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // Default installed WebAPK system bars and splash to the dark
            // header/page, not the portal's decorative accent color. The page
            // meta tag updates the bars for Light, Dark or System selection.
            'background_color' => $darkPalette['page'] ?? '#0E1622',
            'theme_color' => $darkPalette['header'] ?? '#121C2A',
            'icons' => [
                ['src' => '/pwa/icon/192.png?v=2', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon/512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];

        return response()->json($manifest)
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * A pass-through worker permits install promotion in Chromium without
     * storing any private responses or providing unauthenticated offline data.
     */
    public function serviceWorker(Request $request, NetworkSettings $settings): Response
    {
        $site = $this->enabledSite($request, $settings);

        $javascript = <<<'JS'
self.addEventListener('install', () => {
    self.skipWaiting();
});
self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', event => {
    // No Cache API, IndexedDB, precaching or offline pages. Requests remain
    // bound to the server's existing prelaunch and session authorization.
    if (event.request.method !== 'GET' ||
        new URL(event.request.url).origin !== self.location.origin) return;
    event.respondWith(fetch(event.request));
});
JS;
        if (PushDelivery::available($site)) {
            // Ignore all incoming payloads; do not read/store private account
            // data or navigate to a provider-controlled URL.
            $javascript .= <<<'JS'

self.addEventListener('push', event => {
    event.waitUntil(self.registration.showNotification('CanadaMB', {
        body: 'You may have new account activity. Open CanadaMB to check.',
        icon: '/pwa/icon/192.png?v=2',
        tag: 'canadamb-activity',
        renotify: false
    }));
});
self.addEventListener('notificationclick', event => {
    if (event.notification.tag !== 'canadamb-activity') return;
    event.notification.close();
    event.waitUntil(self.clients.openWindow(new URL('/account/notifications', self.location.origin).href));
});
JS;
        }

        return response($javascript, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function icon(Request $request, NetworkSettings $settings, int $size): Response
    {
        abort_unless(in_array($size, [180, 192, 512], true), 404);
        $site = $this->enabledSite($request, $settings);
        // The network launcher uses the same light-blue / navy mark as its
        // header logo in dark mode. Other portals retain their own local accent.
        $iconBackground = $site->isNetwork()
            ? ($settings->forSite($site)['palette']['dark']['primary'] ?? '#75BFFF')
            : $this->accent($site);
        $iconMark = $site->isNetwork() ? '#112339' : '#FFFFFF';
        abort_unless(is_string($iconBackground) && preg_match('/^#[0-9a-fA-F]{6}$/D', $iconBackground), 503);

        // PHP GD is already required by the existing production image pipeline.
        abort_unless(extension_loaded('gd'), 503);
        $image = imagecreatetruecolor($size, $size);
        abort_if($image === false, 503);

        $hex = ltrim($iconBackground, '#');
        $background = imagecolorallocate($image,
            hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $hex = ltrim($iconMark, '#');
        $mark = imagecolorallocate($image,
            hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        imagefill($image, 0, 0, $background);

        // Mountains and a round sun replicate the site's CSS fallback logo.
        // All foreground artwork stays inside the maskable inner safe circle.
        imagefilledellipse($image, (int) ($size * .39), (int) ($size * .34),
            (int) ($size * .135), (int) ($size * .135), $mark);
        imagefilledpolygon($image, [
            (int) ($size * .24), (int) ($size * .72),
            (int) ($size * .43), (int) ($size * .49),
            (int) ($size * .54), (int) ($size * .61),
            (int) ($size * .65), (int) ($size * .45),
            (int) ($size * .78), (int) ($size * .72),
        ], $mark);

        ob_start();
        imagepng($image, null, 9);
        $bytes = ob_get_clean();
        imagedestroy($image);
        abort_unless(is_string($bytes) && $bytes !== '', 503);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function enabledSite(Request $request, NetworkSettings $settings): Site
    {
        $site = $request->attributes->get('site');
        abort_unless($site instanceof Site && ($settings->forSite($site)['pwa_install_enabled'] ?? true), 404);

        return $site;
    }

    private function accent(Site $site): string
    {
        $color = $site->accent['colors'][0] ?? '#2759a5';

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/D', $color) ? $color : '#2759a5';
    }
}
