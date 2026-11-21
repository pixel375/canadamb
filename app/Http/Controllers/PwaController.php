<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Support\NetworkSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function manifest(Request $request, NetworkSettings $settings): JsonResponse
    {
        $site = $this->enabledSite($request, $settings);
        $accent = $this->accent($site);

        $manifest = [
            'id' => '/',
            'name' => $site->name,
            'short_name' => $site->name,
            'description' => $site->description,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $accent,
            'icons' => [
                ['src' => '/pwa/icon/192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon/512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];

        return response()->json($manifest)
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function icon(Request $request, NetworkSettings $settings, int $size): Response
    {
        abort_unless(in_array($size, [180, 192, 512], true), 404);
        $site = $this->enabledSite($request, $settings);
        $accent = $this->accent($site);

        // Generate branded icons on the same origin as the selected portal.
        // PHP GD is already required by the current production image pipeline.
        abort_unless(extension_loaded('gd'), 503);
        $image = imagecreatetruecolor($size, $size);
        abort_if($image === false, 503);

        $hex = ltrim($accent, '#');
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));
        $background = imagecolorallocate($image, $red, $green, $blue);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $background);

        // The artwork stays in the inner 70% for Android maskable icon crops.
        imagefilledellipse($image, (int) ($size * .5), (int) ($size * .5),
            (int) ($size * .68), (int) ($size * .68), $white);
        imagefilledellipse($image, (int) ($size * .4), (int) ($size * .41),
            (int) ($size * .085), (int) ($size * .085), $background);
        imagefilledpolygon($image, [
            (int) ($size * .26), (int) ($size * .66),
            (int) ($size * .42), (int) ($size * .48),
            (int) ($size * .52), (int) ($size * .6),
            (int) ($size * .65), (int) ($size * .42),
            (int) ($size * .76), (int) ($size * .66),
        ], $background);

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
