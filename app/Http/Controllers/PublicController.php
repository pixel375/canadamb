<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Site;
use App\Support\BusinessDirectory;
use App\Support\Community;
use App\Support\JobBoard;
use App\Support\Marketplace;
use App\Support\NetworkSettings;
use App\Support\NetworkUrl;
use App\Support\News;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function home(Request $request, Marketplace $marketplace)
    {
        return view('home', $this->homeData($request->attributes->get('site'), $marketplace, $request->query('location')));
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
        ];
    }

    public function communities(Request $request)
    {
        abort_unless($request->attributes->get('site')->isNetwork(), 404);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'type' => 'nullable|string|max:40']);
        $locations = Location::selectable()->with('site.domain')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(function ($match) use ($q) {
                $match->whereLike('name', '%'.$q.'%')->orWhereExists(function ($alias) use ($q) {
                    $alias->selectRaw('1')->from('location_aliases')->whereColumn('location_id', 'locations.id')->whereLike('name', '%'.$q.'%');
                });
            }))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderBy('name')->paginate(24)->withQueryString();

        if ($request->is('api/*')) {
            return response()->json(['data' => $locations->map(fn (Location $location) => [
                'id' => $location->id, 'name' => $location->name, 'slug' => $location->slug, 'type' => $location->type,
            ]), 'meta' => ['page' => $locations->currentPage(), 'last_page' => $locations->lastPage()]]);
        }

        $types = Location::selectable()->select('type')->distinct()->orderBy('type')->pluck('type');

        return view('communities', compact('locations', 'filters', 'types'));
    }

    public function sitemap(Request $request, Marketplace $marketplace, BusinessDirectory $businesses, News $news)
    {
        $site = $request->attributes->get('site');
        $urls = [NetworkUrl::to($site)];
        if ($site->isNetwork()) {
            $urls[] = NetworkUrl::to($site, '/communities');
        }
        if ($marketplace->enabled($site)) {
            $overridable = in_array('marketplace', app(NetworkSettings::class)->published()['permitted_module_overrides'], true);
            $listings = $marketplace->visible($site)->where(function ($query) use ($site, $overridable) {
                if ($site->isNetwork()) {
                    $query->whereDoesntHave('location.site', fn ($local) => $local->where('status', 'active')->when(
                        $overridable, fn ($community) => $community->where(fn ($settings) => $settings
                            ->whereNull('overrides->modules->marketplace')->orWhere('overrides->modules->marketplace', true)),
                    ));
                }
            })->orderBy('id')->limit(10000);
            if ($listings->exists()) {
                $urls[] = NetworkUrl::to($site, '/marketplace');
            }
            foreach ($listings->cursor() as $listing) {
                $urls[] = NetworkUrl::to($site, '/marketplace/'.$listing->id);
            }
        }
        if ($businesses->enabled($site)) {
            $profiles = $businesses->visible($site)->with('locations.location.site.domain')->orderBy('id')->limit(10000);
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
            $path = $module === 'community' ? Community::DISCUSSIONS_PATH : '/events';
            $urls[] = NetworkUrl::to($site, $path);
            foreach ($query->with('location.site.domain')->orderBy('id')->lazy(250)->take(10000) as $item) {
                if ($community->canonicalSite($item, $module)->id === $site->id) {
                    $urls[] = NetworkUrl::to($site, $path.'/'.$item->id);
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
