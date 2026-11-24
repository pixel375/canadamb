<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Location;
use App\Support\BusinessDirectory;
use App\Support\KeywordSearch;
use App\Support\LocationScope;
use App\Support\NetworkUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function __construct(private readonly BusinessDirectory $directory) {}

    public function index(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->directory->enabled($site), 404);
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'category' => ['nullable', Rule::in(array_keys(BusinessDirectory::CATEGORIES))],
            'location' => 'nullable|string|max:100',
        ]);
        $businesses = $this->directory->visible($site)
            ->with(['locations.location'])
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['name', 'description']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($site->isNetwork() && isset($filters['location']), fn ($query) => $query->whereHas('locations.location', fn ($location) => $location->where('slug', $filters['location'])))
            ->orderBy('name')->orderBy('id')->paginate(18)->withQueryString();

        return view('business.index', compact('businesses', 'filters') + [
            'categories' => BusinessDirectory::CATEGORIES,
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->directory->enabled($site), 404);
        $business = $this->directory->visible($site)->with('locations.location')->where('slug', $slug)->firstOrFail();
        $owner = $this->directory->canonicalSite($business);
        $canonical = NetworkUrl::to($owner, '/businesses/'.$business->slug);
        if ($owner->id !== $site->id) {
            return redirect()->away($canonical, 301, ['Cache-Control' => 'no-store']);
        }
        $branches = $business->locations->when($site->location_id, fn ($locations, $id) => $locations->where('location_id', $id));
        $updates = $this->directory->updates($site->location_id)->whereIn('business_location_id', $branches->pluck('id'))->paginate(12);
        $claimed = $business->members()->wherePivot('role', 'owner')->exists();

        return view('business.show', compact('business', 'branches', 'updates', 'claimed', 'canonical'));
    }

    public function updates(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->directory->enabled($site), 404);

        $filters = $request->validate([
            'location' => ['nullable', 'string', 'max:100', Rule::exists('locations', 'slug')->where('province', 'MB')->where('selectable', true)],
        ]);
        $place = LocationScope::forBrowsing($site, $filters['location'] ?? null);

        return view('business.updates', [
            'updates' => $this->directory->updates($place?->id)->paginate(18)->withQueryString(),
            'place' => $place,
            'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'filters' => $filters,
        ]);
    }

    public function claim(Request $request, string $slug)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->directory->enabled($site), 404);
        $data = $request->validate(['contact_email' => 'required|email|max:254', 'evidence' => 'required|string|min:30|max:2400']);
        DB::transaction(function () use ($site, $slug, $request, $data) {
            $business = $this->directory->visible($site)->where('slug', $slug)->lockForUpdate()->firstOrFail();
            if ($business->members()->wherePivot('role', 'owner')->exists()) {
                throw ValidationException::withMessages(['claim' => 'This profile already has an owner. Contact the network for a dispute.']);
            }
            if (DB::table('business_claims')->where([
                'business_id' => $business->id, 'user_id' => $request->user()->id, 'status' => 'pending',
            ])->exists()) {
                throw ValidationException::withMessages(['claim' => 'You already have a claim waiting for review.']);
            }
            DB::table('business_claims')->insert($data + [
                'business_id' => $business->id, 'user_id' => $request->user()->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return redirect('/account/businesses')->with('status', 'Your claim was submitted for manual verification.');
    }

    public function mine(Request $request)
    {
        $user = $request->user()->id;
        $businesses = Business::managedBy($user)->with(['locations.location', 'members' => fn ($query) => $query->whereKey($user)])
            ->orderBy('name')->paginate(20);
        $claims = DB::table('business_claims')->join('businesses', 'businesses.id', '=', 'business_claims.business_id')
            ->where('business_claims.user_id', $user)->latest('business_claims.id')->take(15)
            ->get(['businesses.name', 'business_claims.status', 'business_claims.created_at']);

        return view('business.mine', compact('businesses', 'claims'));
    }

    public function create(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->directory->enabled($site), 404);

        return view('business.form', [
            'business' => new Business, 'places' => $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect(),
            'branches' => collect(), 'categories' => BusinessDirectory::CATEGORIES,
        ]);
    }

    public function store(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless($this->directory->enabled($site), 404);
        $data = $request->validate($this->profileRules() + [
            'name' => 'required|string|max:140', 'category' => ['required', Rule::in(array_keys(BusinessDirectory::CATEGORIES))],
            'address' => 'required|string|max:255', 'location_id' => 'nullable|integer',
        ]);
        $place = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
        try {
            DB::transaction(function () use ($request, $data, $place) {
                $branch = [
                    'location_id' => $place, 'address' => $data['address'],
                    'address_key' => BusinessDirectory::addressKey($data['name'], $data['address']),
                ];
                $business = Business::create(collect($data)->except('address', 'location_id')->all() + [
                    'slug' => BusinessDirectory::slug($data['name']), 'status' => 'pending',
                ]);
                $business->locations()->create($branch);
                $business->members()->attach($request->user()->id, ['role' => 'owner']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['address' => 'This business and address already appear in the directory. Please request a claim.']);
        }

        return redirect('/account/businesses')->with('status', 'Business profile submitted for review.');
    }

    public function edit(Request $request, int $business)
    {
        $item = $this->managed($request, $business);
        $site = $request->attributes->get('site');
        $branches = $item->locations()->with('location')->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->get();

        $recentUpdates = DB::table('business_updates')->whereIn('business_location_id', $branches->pluck('id'))
            ->latest('id')->limit(10)->get(['id', 'title', 'type', 'status', 'created_at']);

        return view('business.form', ['business' => $item, 'branches' => $branches, 'recentUpdates' => $recentUpdates]);
    }

    public function update(Request $request, int $business)
    {
        $item = $this->managed($request, $business);
        $data = $request->validate($this->profileRules());
        $item->update($data);

        return redirect('/account/businesses/'.$business)->with('status', 'Business profile saved.');
    }

    public function postUpdate(Request $request, int $business)
    {
        $item = $this->managed($request, $business);
        $data = $request->validate([
            'business_location_id' => 'required|integer', 'type' => 'required|in:announcement,promotion,update',
            'title' => 'required|string|max:140', 'body' => 'required|string|max:3000',
        ]);
        $branch = $item->locations()->findOrFail($data['business_location_id']);
        LocationScope::forPublication($request->attributes->get('site'), $branch->location_id);

        DB::transaction(function () use ($request, $item, $data) {
            $locked = Business::lockForUpdate()->findOrFail($item->id);
            abort_unless($locked->status === 'active', 409);
            if (DB::table('business_updates')->whereIn('business_location_id', $locked->locations()->select('id'))
                ->where('created_at', '>=', now()->subDay())->count() >= 5) {
                throw ValidationException::withMessages(['title' => 'This profile has reached its daily update limit.']);
            }
            DB::table('business_updates')->insert($data + [
                'author_id' => $request->user()->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return redirect('/account/businesses/'.$business)->with('status', 'Business update submitted for review.');
    }

    private function managed(Request $request, int $id): Business
    {
        return Business::managedBy($request->user()->id)->findOrFail($id);
    }

    private function profileRules(): array
    {
        return [
            'description' => 'nullable|string|max:2500', 'website' => 'nullable|url:http,https|max:255',
            'public_email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:32',
            'hours' => 'nullable|string|max:1000',
        ];
    }
}
