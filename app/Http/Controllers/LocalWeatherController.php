<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\LocalWeather;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;

final class LocalWeatherController extends Controller
{
    public function show(Request $request, int $location, LocalWeather $weather, NetworkSettings $settings)
    {
        $site = $request->attributes->get('site');
        $published = $settings->published();
        abort_unless(LocalWeather::configured($published), 404);
        $place = Location::selectable()->when($site->location_id, fn ($query, $id) => $query->whereKey($id))->findOrFail($location);
        abort_unless(LocalWeather::supported($place), 404);
        $current = $weather->current($place, $published);

        return response()->json(['weather' => $current], $current ? 200 : 503)->header('Cache-Control', 'private, no-store');
    }
}
