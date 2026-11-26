<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class LocalWeather
{
    public const DAILY_LIMIT = 2000;

    private static function budgetKey(): string
    {
        return 'local-weather:budget:'.now('UTC')->toDateString();
    }

    public function dailyUsage(): ?int
    {
        try {
            return RateLimiter::attempts(self::budgetKey());
        } catch (Throwable) {
            return null;
        }
    }

    public static function configured(array $settings): bool
    {
        return ($settings['weather_enabled'] ?? false) === true && filled($settings['weather_key_encrypted'] ?? null);
    }

    public static function supported(?Location $place): bool
    {
        return $place && is_numeric($place->latitude) && is_numeric($place->longitude)
            && (float) $place->latitude >= 49 && (float) $place->latitude <= 60
            && (float) $place->longitude + 103 >= 0 && (float) $place->longitude + 88 <= 0;
    }

    public function current(Location $place, array $settings, bool $diagnostic = false): ?array
    {
        if (self::configured($settings) === false || self::supported($place) === false) {
            return null;
        }
        $coordinates = sprintf('%.3f,%.3f', $place->latitude, $place->longitude);
        $cacheKey = 'local-weather:v2:'.($diagnostic ? 'diagnostic:' : '').$place->id.':'.hash('sha256', $coordinates.'|'.$settings['weather_key_encrypted']);
        try {
            if (($cached = Cache::get($cacheKey)) !== null) {
                return ($cached['unavailable'] ?? false) ? null : $cached;
            }
            $result = Cache::lock($cacheKey.':lock', 15)->get(function () use ($coordinates, $settings, $cacheKey) {
                if (($cached = Cache::get($cacheKey)) !== null) {
                    return ($cached['unavailable'] ?? false) ? null : $cached;
                }
                // Shared daily ceiling: a 31-day month remains below 100,000 calls.
                $allowed = Cache::lock('local-weather:budget-lock', 5)->get(function () {
                    $bucket = self::budgetKey();
                    if (RateLimiter::tooManyAttempts($bucket, self::DAILY_LIMIT)) {
                        return false;
                    }
                    RateLimiter::hit($bucket, 86400);

                    return true;
                });
                if ($allowed !== true) {
                    return null;
                }
                try {
                    $key = Crypt::decryptString($settings['weather_key_encrypted']);
                    $response = Http::acceptJson()->connectTimeout(2)->timeout(4)->withoutRedirecting()
                        ->get('https://api.weatherapi.com/v1/current.json', [
                            'key' => $key, 'q' => $coordinates, 'aqi' => 'no',
                        ]);
                    $current = $response->successful() ? $response->json('current') : null;
                    if (is_array($current) && is_numeric($current['temp_c'] ?? null) && abs((float) $current['temp_c']) <= 80
                        && is_string($current['condition']['text'] ?? null) && mb_strlen($current['condition']['text']) <= 80
                        && is_numeric($current['last_updated_epoch'] ?? null)
                        && (int) $current['last_updated_epoch'] <= now()->addMinutes(5)->timestamp
                        && (int) $current['last_updated_epoch'] >= now()->subHours(2)->timestamp) {
                        $result = ['temperature_c' => round((float) $current['temp_c']), 'condition' => $current['condition']['text'],
                            'observed_at' => (int) $current['last_updated_epoch']];
                        $seconds = min(2700, $result['observed_at'] + 7200 - now()->timestamp);
                        if ($seconds > 0) {
                            Cache::put($cacheKey, $result, $seconds);
                        }

                        return $result;
                    }
                } catch (Throwable) {
                    // Provider exceptions may contain request URLs with credentials. Never log them.
                }
                Cache::put($cacheKey, ['unavailable' => true], now()->addMinutes(10));

                return null;
            });

            return is_array($result) ? $result : null;
        } catch (Throwable) {
            // Weather is optional. Cache/provider failure must not break site navigation.
            return null;
        }
    }
}
