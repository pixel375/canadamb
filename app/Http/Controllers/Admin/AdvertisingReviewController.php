<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Support\Audit;
use App\Support\CommercialRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvertisingReviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(CommercialRecords::enabled(), 404);
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,withdrawn']);
        $items = AdvertisingCampaign::with(['business', 'site'])->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->paginate(20)->withQueryString();

        return view('admin.advertising-review', compact('items', 'filters'));
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
