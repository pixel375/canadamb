<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class LocalContextController extends Controller
{
    public function edit(NetworkSettings $settings)
    {
        $state = $settings->state();
        $draft = $settings->revision($state->draft_id ?? $state->published_id);
        $published = $settings->revision($state->published_id);

        return response()->view('admin.local-context', compact('state', 'draft', 'published'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, NetworkSettings $settings)
    {
        $body = $request->isJson() ? $request->json() : $request->request;
        $key = $body->all()['weather_api_key'] ?? null;
        $body->remove('weather_api_key');
        $request->query->remove('weather_api_key');
        $data = $request->validate([
            'configuration_version' => 'required|string|size:64',
            'local_clock_enabled' => 'required|boolean', 'clock_format' => 'required|in:12,24',
            'weather_enabled' => 'required|boolean', 'credential_action' => 'required|in:keep,replace,remove',
        ]);
        if ($data['credential_action'] === 'replace' && (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key))) {
            throw ValidationException::withMessages(['weather_api_key' => 'Enter a valid WeatherAPI.com key.']);
        }
        $settings->saveDraft($request->user()->id, function (array $current) use ($data, $key) {
            $current['local_clock_enabled'] = (bool) $data['local_clock_enabled'];
            $current['clock_format'] = $data['clock_format'];
            $current['weather_enabled'] = (bool) $data['weather_enabled'];
            if ($data['credential_action'] === 'replace') {
                $current['weather_key_encrypted'] = Crypt::encryptString($key);
            } elseif ($data['credential_action'] === 'remove') {
                unset($current['weather_key_encrypted']);
            }
            if ($current['weather_enabled'] && empty($current['weather_key_encrypted'])) {
                throw ValidationException::withMessages(['weather_enabled' => 'Configure a credential before enabling weather.']);
            }

            return $current;
        }, $data['configuration_version']);

        return redirect('/admin/local-context')->with('status', 'Local context draft saved. Publish network settings to apply it.');
    }
}
