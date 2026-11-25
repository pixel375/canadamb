<?php

namespace App\Http\Controllers;

use App\Models\JobSearch;
use App\Models\User;
use App\Support\JobBoard;
use App\Support\JobSearches;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobSearchController extends Controller
{
    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        $items = $this->owned($request)->latest('id')->get();
        $counts = $items->mapWithKeys(fn (JobSearch $item) => [$item->id => $item->alerts ? JobSearches::newMatches($item, $site)->count() : 0]);

        return view('jobs.searches', compact('items', 'counts'));
    }

    public function store(Request $request)
    {
        $this->owned($request);
        $data = $request->validate(JobBoard::searchRules() + ['name' => 'required|string|max:80', 'alerts' => 'nullable|boolean']);
        $site = $request->attributes->get('site');
        $filters = array_intersect_key($data, JobBoard::searchRules());
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        if (! $site->isNetwork()) {
            unset($filters['location']);
        }
        if (isset($filters['minimum_pay'])) {
            $filters['minimum_pay'] = number_format((float) $filters['minimum_pay'], 2, '.', '');
        }
        ksort($filters);
        $key = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($request, $site, $data, $filters, $key) {
            User::lockForUpdate()->findOrFail($request->user()->id);
            $owned = JobSearch::where('user_id', $request->user()->id);
            if ((clone $owned)->where('site_id', $site->id)->where('filter_key', $key)->exists()) {
                throw ValidationException::withMessages(['name' => 'You already saved these filters on this portal.']);
            }
            if ($owned->count() >= 10) {
                throw ValidationException::withMessages(['name' => 'You can save up to ten searches across the network.']);
            }
            JobSearch::create([
                'user_id' => $request->user()->id, 'site_id' => $site->id, 'name' => $data['name'],
                'filters' => $filters, 'filter_key' => $key, 'alerts' => (bool) ($data['alerts'] ?? false), 'checked_at' => now(),
            ]);
        }, attempts: 3);

        return $this->done();
    }

    public function show(Request $request, int $search)
    {
        $item = $this->owned($request)->findOrFail($search);
        $site = $request->attributes->get('site');
        $filters = $request->validate(['view' => 'nullable|in:new,all']);
        $onlyNew = $item->alerts && ($filters['view'] ?? 'new') === 'new';
        $query = $onlyNew ? JobSearches::newMatches($item, $site) : JobSearches::matching($item, $site);
        $jobs = $query->latest('published_at')->latest('id')->paginate(18)->withQueryString();

        return view('jobs.search-matches', compact('item', 'jobs', 'onlyNew'));
    }

    public function preferences(Request $request, int $search)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'alerts' => 'required|boolean']);
        DB::transaction(function () use ($request, $search, $data) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($search);
            $alerts = (bool) $data['alerts'];
            if ($alerts !== $item->alerts) {
                $item->checked_at = now();
            }
            $item->fill(['name' => $data['name'], 'alerts' => $alerts])->save();
        }, attempts: 3);

        return $this->done();
    }

    public function read(Request $request, int $search)
    {
        DB::transaction(function () use ($request, $search) {
            $item = $this->owned($request)->lockForUpdate()->findOrFail($search);
            abort_unless($item->alerts, 409);
            $item->forceFill(['checked_at' => now()])->save();
        }, attempts: 3);

        return $this->done();
    }

    public function destroy(Request $request, int $search)
    {
        $this->owned($request)->findOrFail($search)->delete();

        return $this->done();
    }

    private function owned(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless(JobSearches::enabled($site), 404);

        return JobSearch::where('user_id', $request->user()->id)->where('site_id', $site->id);
    }

    private function done()
    {
        return redirect('/account/job-searches')->with('status', 'Saved job searches updated.');
    }
}
