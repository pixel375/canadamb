<?php

namespace App\Http\Middleware;

use App\Support\LocationScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPreferredCommunity
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->attributes->get('site');
        // Dedicated portals have a fixed geographic scope. Ignore foreign,
        // stale or malformed public location filters before validation.
        if ($site && ! $site->isNetwork() && $request->isMethod('GET')
            && $request->is('/', 'marketplace', 'real-estate', 'businesses', 'business-updates', 'community', 'jobs', 'events', 'events/calendar', 'news')) {
            $request->query->remove('location');

            return $next($request);
        }

        if ($site?->isNetwork() && $request->isMethod('GET')
            && $request->is('/', 'marketplace', 'real-estate', 'businesses', 'business-updates', 'community', 'jobs', 'events', 'news')
            && ! $request->query->has('location')
            && ($place = LocationScope::preferred($site, $request->cookie(LocationScope::PREFERENCE_COOKIE)))) {
            $query = $request->query->all();
            $query['location'] = $request->is('jobs') ? $place->name : $place->slug;

            return redirect('/'.ltrim($request->path(), '/').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))
                ->header('Cache-Control', 'private, no-store');
        }

        return $next($request);
    }
}
