<?php

namespace App\Http\Controllers;

use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Models\Site;
use App\Support\Audit;
use App\Support\CommercialCatalog;
use App\Support\CommercialRecords;
use App\Support\KeywordSearch;
use App\Support\NetworkSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvertisingController extends Controller
{
    public function index(Request $request, int $business)
    {
        $item = $this->owned($request, $business);
        $locations = $item->openLocations()->pluck('location_id');
        $portals = Site::where('status', 'active')->where(fn ($query) => $query->whereNull('location_id')->orWhereIn('location_id', $locations))->orderBy('name')->get();
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,withdrawn', 'q' => 'nullable|string|max:100',
            'site_id' => 'nullable|integer|exists:sites,id', 'placement' => ['nullable', Rule::in(array_keys(CommercialCatalog::PLACEMENTS))]]);
        $owned = AdvertisingCampaign::where('business_id', $business);
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $historyPortals = Site::whereIn('id', (clone $owned)->select('site_id'))->orderBy('name')->get(['id', 'name']);
        $campaigns = $owned->with('site')
            ->when($filters['site_id'] ?? null, fn ($query, $id) => $query->where('site_id', $id))
            ->when($filters['placement'] ?? null, fn ($query, $placement) => $query->where('placement', $placement))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))->latest('id')->paginate(15)->withQueryString();
        $catalog = CommercialCatalog::fromSettings(app(NetworkSettings::class)->published());
        $trial = CommercialRecords::hasTrial($item);
        $canExport = $trial && config('network.commercial_trial_export');

        return view('business.advertising', compact('item', 'portals', 'campaigns', 'catalog', 'trial', 'filters', 'canExport', 'counts', 'historyPortals'));
    }

    public function export(Request $request, int $business)
    {
        $item = $this->owned($request, $business);
        abort_unless(config('network.commercial_trial_export') && CommercialRecords::hasTrial($item), 404);
        $filters = $request->validate(['after_id' => 'nullable|integer|min:0']);
        $after = (int) ($filters['after_id'] ?? 0);
        $page = AdvertisingCampaign::where('business_id', $business)->where('id', '>', $after)->with('site:id,name')->orderBy('id')->limit(501)->get();
        $next = $page->count() > 500 ? $page->take(500)->last()->id : null;
        $records = $page->take(500)->values()->map(fn ($campaign) => [
            'id' => $campaign->id, 'portal' => $campaign->site->name,
            'placement' => $campaign->placement, 'headline' => $campaign->title, 'text' => $campaign->body,
            'start_date' => $campaign->starts_on->toDateString(), 'end_date_exclusive' => $campaign->endsOn()->toDateString(),
            'estimate_cad_cents' => $campaign->estimated_cents, 'status' => $campaign->status,
            'planning_phase' => $campaign->planningPhase(), 'review_note' => $campaign->review_note,
        ]);

        return response()->json(['business_id' => $business, 'planning_only' => true, 'after_id' => $after, 'next_after_id' => $next, 'campaigns' => $records])
            ->header('Content-Disposition', 'attachment; filename="campaign-planning.json"')
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $business, NetworkSettings $settings)
    {
        $this->owned($request, $business);
        $data = $request->validate([
            'site_id' => 'required|integer|min:1',
            'placement' => ['required', Rule::in(array_keys(CommercialCatalog::PLACEMENTS))],
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:500',
            'starts_on' => 'required|date_format:Y-m-d|after_or_equal:'.today('America/Winnipeg')->toDateString().'|before_or_equal:'.today('America/Winnipeg')->addYear()->toDateString(),
            'weeks' => 'required|integer|min:1|max:12',
        ]);
        DB::transaction(function () use ($request, $business, $settings, $data) {
            Business::lockForUpdate()->findOrFail($business);
            $item = $this->owned($request, $business);
            $site = Site::findOrFail($data['site_id']);
            if (! CommercialRecords::eligible($item, $site, $data['placement'])) {
                throw ValidationException::withMessages(['site_id' => 'The business or selected placement is not eligible on that portal.']);
            }
            $today = today('America/Winnipeg')->toDateString();
            $open = AdvertisingCampaign::where('business_id', $business)->whereIn('status', ['pending', 'approved'])
                ->whereDate('starts_on', '>=', today('America/Winnipeg')->subWeeks(12)->toDateString())->get()
                ->filter(fn ($campaign) => in_array($campaign->planningPhase($today), ['pending', 'scheduled', 'in-window'], true))->count();
            if ($open >= 10) {
                throw ValidationException::withMessages(['title' => 'At most ten open campaign requests are allowed.']);
            }
            $duplicate = AdvertisingCampaign::where('business_id', $business)->where('site_id', $site->id)->where('placement', $data['placement'])
                ->whereDate('starts_on', $data['starts_on'])->where('weeks', $data['weeks'])->where('title', $data['title'])
                ->whereIn('status', ['pending', 'approved'])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['title' => 'An equivalent open campaign request already exists.']);
            }
            $revision = $settings->state()->published_id;
            $catalog = CommercialCatalog::fromSettings($settings->revision($revision));
            $campaign = AdvertisingCampaign::create($data + [
                'business_id' => $business, 'author_id' => $request->user()->id,
                'estimated_cents' => CommercialCatalog::estimate($catalog, $data['placement'], (int) $data['weeks']),
                'rate_revision_id' => $revision,
            ]);
            Audit::record('advertising.requested', $request->user()->id, context: ['campaign' => $campaign->id, 'business' => $business]);
        }, attempts: 3);

        return redirect('/account/businesses/'.$business.'/advertising')->with('status', 'Private campaign request submitted. No advertising or payment has been activated.');
    }

    public function withdraw(Request $request, int $business, int $campaign)
    {
        $this->owned($request, $business);
        $data = $request->validate(['version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($request, $business, $campaign, $data) {
            Business::lockForUpdate()->findOrFail($business);
            $this->owned($request, $business);
            $item = AdvertisingCampaign::where('business_id', $business)->lockForUpdate()->findOrFail($campaign);
            abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
            if (isset($data['version'])) {
                abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'Campaign changed. Reload before withdrawing.');
            }
            $item->forceFill(['status' => 'withdrawn'])->save();
            Audit::record('advertising.withdrawn', $request->user()->id, context: ['campaign' => $campaign]);
        }, attempts: 3);

        return redirect('/account/businesses/'.$business.'/advertising')->with('status', 'Campaign request withdrawn.');
    }

    private function owned(Request $request, int $business): Business
    {
        abort_unless(CommercialRecords::enabled(), 404);

        return Business::where('status', 'active')->managedBy($request->user()->id, ['owner'])->findOrFail($business);
    }
}
