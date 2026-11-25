<?php

namespace App\Http\Controllers;

use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Models\Site;
use App\Support\Audit;
use App\Support\CommercialCatalog;
use App\Support\CommercialRecords;
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
        $portals = Site::where('status', 'active')->orderBy('name')->get()->filter(fn ($site) => ! $site->location_id || $item->locations()->where('location_id', $site->location_id)->exists());
        $campaigns = AdvertisingCampaign::where('business_id', $business)->with('site')->latest('id')->paginate(15);
        $catalog = CommercialCatalog::fromSettings(app(NetworkSettings::class)->published());
        $trial = CommercialRecords::hasTrial($item);

        return view('business.advertising', compact('item', 'portals', 'campaigns', 'catalog', 'trial'));
    }

    public function store(Request $request, int $business, NetworkSettings $settings)
    {
        $this->owned($request, $business);
        $data = $request->validate([
            'site_id' => 'required|integer|min:1',
            'placement' => ['required', Rule::in(array_keys(CommercialCatalog::PLACEMENTS))],
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:500',
            'starts_on' => 'required|date_format:Y-m-d|after_or_equal:'.today('America/Winnipeg')->toDateString(),
            'weeks' => 'required|integer|min:1|max:12',
        ]);
        DB::transaction(function () use ($request, $business, $settings, $data) {
            Business::lockForUpdate()->findOrFail($business);
            $item = $this->owned($request, $business);
            $site = Site::findOrFail($data['site_id']);
            if (! CommercialRecords::eligible($item, $site, $data['placement'])) {
                throw ValidationException::withMessages(['site_id' => 'The business or selected placement is not eligible on that portal.']);
            }
            if (AdvertisingCampaign::where('business_id', $business)->whereIn('status', ['pending', 'approved'])->count() >= 10) {
                throw ValidationException::withMessages(['title' => 'At most ten open campaign requests are allowed.']);
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
        DB::transaction(function () use ($request, $business, $campaign) {
            Business::lockForUpdate()->findOrFail($business);
            $this->owned($request, $business);
            $item = AdvertisingCampaign::where('business_id', $business)->lockForUpdate()->findOrFail($campaign);
            abort_unless(in_array($item->status, ['pending', 'approved'], true), 409);
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
