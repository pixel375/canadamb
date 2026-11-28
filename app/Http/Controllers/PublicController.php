<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Site;
use App\Support\BusinessDirectory;
use App\Support\Community;
use App\Support\JobBoard;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\Marketplace;
use App\Support\NetworkSettings;
use App\Support\NetworkUrl;
use App\Support\News;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicController extends Controller
{
    public function home(Request $request, Marketplace $marketplace)
    {
        $site = $request->attributes->get('site');
        $preferred = LocationScope::preferred($site, $request->cookie(LocationScope::PREFERENCE_COOKIE));
        if ($preferred && ! $request->query->has('location')) {
            return redirect(LocationScope::browseUrl($site, '/', $preferred))->header('Cache-Control', 'private, no-store');
        }

        return view('home', $this->homeData($site, $marketplace, $request->query('location')));
    }

    public function rememberCommunity(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($site->isNetwork(), 404);
        $data = $request->validate(['location' => 'nullable|string|max:150']);
        $slug = $data['location'] ?? null;
        $place = LocationScope::preferred($site, $slug);
        if ($slug !== null && ! $place) {
            throw ValidationException::withMessages(['location' => 'Choose a supported Manitoba community.']);
        }

        $response = redirect($place ? LocationScope::browseUrl($site, '/', $place) : '/')->header('Cache-Control', 'private, no-store');

        return $place
            ? $response->withCookie(cookie(LocationScope::PREFERENCE_COOKIE, $place->slug, 525600, '/', null, $request->isSecure(), true, false, 'lax'))
            : $response->withoutCookie(LocationScope::PREFERENCE_COOKIE, '/');
    }

    public function homeData(Site $site, Marketplace $marketplace, mixed $preferred = null): array
    {
        $location = $site->isNetwork() && is_string($preferred)
            ? Location::selectable()->where('slug', $preferred)->first()
            : $site->location;
        $latest = $marketplace->enabled($site)
            ? $marketplace->visible($site)->when($location, fn ($query) => $query->where('location_id', $location->id))
                ->latest('published_at')->orderByDesc('id')->limit(50)->get()
            : collect();
        $differentSellers = $latest->unique('user_id');
        $directory = app(BusinessDirectory::class);
        $updates = $directory->enabled($site) ? $directory->updates($location?->id)->limit(50)->get() : collect();
        $differentBusinesses = $updates->unique(fn ($update) => $update->branch->business_id);
        $news = app(News::class);
        $community = app(Community::class);
        $jobs = app(JobBoard::class);

        return [
            'location' => $location, 'latest' => $differentSellers->concat($latest->diff($differentSellers))->take(5),
            'businessUpdates' => $differentBusinesses->concat($updates->diff($differentBusinesses))->take(5),
            'articles' => $news->enabled($site) ? $news->visible($location?->id)->limit(4)->get() : collect(),
            'recentDiscussions' => $community->enabled($site, 'community')
                ? $community->threads($location?->id)->latest('last_activity_at')->latest('id')->limit(4)->get() : collect(),
            'upcomingEvents' => $community->enabled($site, 'events')
                ? $community->upcoming($community->events($location?->id))->oldest('starts_at')->oldest('id')->limit(4)->get() : collect(),
            'latestJobs' => $jobs->enabled($site)
                ? $jobs->visible($location?->id)->latest('published_at')->latest('id')->limit(4)->get() : collect(),
            'locations' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'activePortals' => $site->isNetwork() ? Site::with(['domain', 'location'])->where('status', 'active')
                ->whereNotNull('location_id')->whereHas('domain')->whereHas('location', fn ($query) => $query->selectable())
                ->when($location, fn ($query) => $query->orderByRaw('CASE WHEN location_id = ? THEN 0 ELSE 1 END', [$location->id]))
                ->orderBy('name')->orderBy('id')->limit(8)->get() : collect(),
        ];
    }

    public function communities(Request $request)
    {
        $site = $request->attributes->get('site');
        if (! $request->is('api/*')) {
            $portals = Site::with(['domain', 'location'])->where('status', 'active')->whereNotNull('location_id')
                ->whereHas('domain')->whereHas('location', fn ($query) => $query->selectable())
                ->whereKeyNot($site->id)->orderBy('name')->orderBy('id')->get();
            $choices = $site->isNetwork() ? Location::selectable()->orderBy('name')->orderBy('id')->get() : collect();
            $preferredCommunity = LocationScope::preferred($site, $request->cookie(LocationScope::PREFERENCE_COOKIE));

            return view('communities', compact('site', 'portals', 'choices', 'preferredCommunity'));
        }
        abort_unless($site->isNetwork(), 404);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'type' => 'nullable|string|max:40',
            'portal' => 'nullable|in:dedicated,network']);
        $locations = Location::selectable()->with('site.domain');
        foreach (KeywordSearch::patterns($filters['q'] ?? '') as $pattern) {
            $locations->where(fn ($match) => $match->whereRaw("LOWER(locations.name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereExists(fn ($alias) => $alias->selectRaw('1')->from('location_aliases')->whereColumn('location_id', 'locations.id')
                    ->whereRaw("LOWER(location_aliases.name) LIKE ? ESCAPE '!'", [$pattern])));
        }
        $activePortal = fn ($query) => $query->where('status', 'active')->whereHas('domain');
        $locations = $locations
            ->when(($filters['portal'] ?? null) === 'dedicated', fn ($query) => $query->whereHas('site', $activePortal))
            ->when(($filters['portal'] ?? null) === 'network', fn ($query) => $query->whereDoesntHave('site', $activePortal))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderBy('name')->orderBy('id')->paginate(24)->withQueryString();

        if ($request->is('api/*')) {
            return response()->json(['data' => $locations->map(fn (Location $location) => [
                'id' => $location->id, 'name' => $location->name, 'slug' => $location->slug, 'type' => $location->type,
            ]), 'meta' => ['page' => $locations->currentPage(), 'last_page' => $locations->lastPage(), 'total' => $locations->total()]]);
        }

        abort(404);
    }

    public function sitemap(Request $request, Marketplace $marketplace, BusinessDirectory $businesses, News $news)
    {
        $site = $request->attributes->get('site');
        $urls = [NetworkUrl::to($site)];
        $urls[] = NetworkUrl::to($site, '/communities');
        $urls[] = NetworkUrl::to($site, '/faq');
        if ($marketplace->enabled($site)) {
            $overridable = in_array('marketplace', app(NetworkSettings::class)->published()['permitted_module_overrides'], true);
            $listings = $marketplace->visible($site);
            if ((clone $listings)->exists()) {
                $urls[] = NetworkUrl::to($site, '/marketplace');
            }
            $listings->where(function ($query) use ($site, $overridable) {
                if ($site->isNetwork()) {
                    $query->whereDoesntHave('location.site', fn ($local) => $local->where('status', 'active')->when(
                        $overridable, fn ($community) => $community->where(fn ($settings) => $settings
                            ->whereNull('overrides->modules->marketplace')->orWhere('overrides->modules->marketplace', true)),
                    ));
                }
            })->orderBy('id')->limit(10000);
            foreach ($listings->cursor() as $listing) {
                $urls[] = NetworkUrl::to($site, '/marketplace/'.$listing->id);
            }
        }
        if ($businesses->enabled($site)) {
            $profiles = $businesses->visible($site)->with('openLocations.location.site.domain')->orderBy('id')->limit(10000);
            if ($profiles->exists()) {
                $urls[] = NetworkUrl::to($site, '/businesses');
            }
            foreach ($profiles->lazy(250)->take(10000) as $business) {
                if ($businesses->canonicalSite($business)->id === $site->id) {
                    $urls[] = NetworkUrl::to($site, '/businesses/'.$business->slug);
                }
            }
        }
        if ($news->enabled($site)) {
            $articles = $news->visible($site->location_id)->with('location.site.domain')->reorder('id')->limit(10000);
            if ($articles->exists()) {
                $urls[] = NetworkUrl::to($site, '/news');
            }
            foreach ($articles->lazy(250)->take(10000) as $article) {
                if ($news->canonicalSite($article)->id === $site->id) {
                    $urls[] = NetworkUrl::to($site, '/news/'.$article->id);
                }
            }
        }
        $community = app(Community::class);
        foreach (['community' => $community->threads($site->location_id), 'events' => $community->upcoming($community->events($site->location_id))] as $module => $query) {
            if (! $community->enabled($site, $module) || ! $query->exists()) {
                continue;
            }
            $urls[] = NetworkUrl::to($site, '/'.$module);
            foreach ($query->with('location.site.domain')->orderBy('id')->lazy(250)->take(10000) as $item) {
                if ($community->canonicalSite($item, $module)->id === $site->id) {
                    $urls[] = NetworkUrl::to($site, '/'.$module.'/'.$item->id);
                }
            }
        }
        $jobs = app(JobBoard::class);
        if ($jobs->enabled($site)) {
            $postings = $jobs->visible($site->location_id);
            if ($postings->exists()) {
                $urls[] = NetworkUrl::to($site, '/jobs');
            }
            foreach ($postings->with('branch.location.site.domain')->orderBy('id')->lazy(250)->take(10000) as $job) {
                if ($jobs->canonicalSite($job)->id === $site->id) {
                    $urls[] = NetworkUrl::to($site, '/jobs/'.$job->id);
                }
            }
        }

        return response()->view('sitemap', compact('urls'))->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(Request $request)
    {
        $site = $request->attributes->get('site');

        $directives = "User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /auth\n";
        if (! config('network.staging')) {
            $directives .= 'Sitemap: '.NetworkUrl::to($site, '/sitemap.xml')."\n";
        }

        return response($directives)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
