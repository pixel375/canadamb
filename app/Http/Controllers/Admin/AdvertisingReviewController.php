<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Models\Site;
use App\Support\AdvertisingSchedule;
use App\Support\Audit;
use App\Support\CommercialCatalog;
use App\Support\CommercialRecords;
use App\Support\KeywordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvertisingReviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $filters = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,withdrawn', 'q' => 'nullable|string|max:100',
            'site_id' => 'nullable|integer|exists:sites,id',
        ]);
        $portals = Site::orderBy('name')->get(['id', 'name']);
        $items = AdvertisingCampaign::with(['business', 'site'])->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['site_id'] ?? null, fn ($query, $site) => $query->where('site_id', $site))
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['title', 'body']))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(20)->withQueryString();

        return view('admin.advertising-review', compact('items', 'filters', 'portals'));
    }

    public function schedule(Request $request, AdvertisingSchedule $schedule)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $filters = $request->validate(['site_id' => 'nullable|integer|min:1', 'date' => 'nullable|date_format:Y-m-d']);
        $portals = Site::where('status', 'active')->orderBy('name')->get();
        $portal = $portals->firstWhere('id', (int) ($filters['site_id'] ?? 0));
        $date = $filters['date'] ?? today('America/Winnipeg')->toDateString();
        $placements = collect(CommercialCatalog::PLACEMENTS)->map(fn ($module, $placement) => $portal ? $schedule->eligible($portal, $placement, $date) : collect());

        return view('admin.advertising-schedule', compact('portals', 'portal', 'date', 'placements'));
    }

    public function decide(Request $request, int $campaign)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'note' => 'required|string|min:8|max:1200']);
        DB::transaction(function () use ($request, $campaign, $data) {
            $record = AdvertisingCampaign::findOrFail($campaign);
            $business = Business::lockForUpdate()->findOrFail($record->business_id);
            $item = AdvertisingCampaign::lockForUpdate()->findOrFail($campaign);
            abort_unless($item->status === 'pending', 409);
            abort_if((int) $item->author_id === $request->user()->id || $business->members()->whereKey($request->user()->id)->exists(), 403);
            if ($data['decision'] === 'approved' && (! CommercialRecords::eligible($business, $item->site, $item->placement)
                || $item->starts_on->toDateString() < today('America/Winnipeg')->toDateString())) {
                throw ValidationException::withMessages(['decision' => 'The request is no longer eligible. Reject it and ask the owner to submit a new request.']);
            }
            $item->forceFill(['status' => $data['decision'], 'review_note' => $data['note'], 'reviewer_id' => $request->user()->id, 'reviewed_at' => now()])->save();
            Audit::record('advertising.'.$data['decision'], $request->user()->id, context: ['campaign' => $campaign]);
        }, attempts: 3);

        return redirect('/admin/advertising')->with('status', 'Planning review recorded. No ad delivery or billing is enabled.');
    }
}
