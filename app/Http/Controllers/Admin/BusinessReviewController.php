<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessUpdate;
use App\Support\Audit;
use App\Support\ModerationDecision;
use App\Support\NotificationInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessReviewController extends Controller
{
    public function index()
    {
        return view('admin.business-review', [
            'businesses' => Business::with('locations.location')->where('status', 'pending')->latest()->paginate(15),
            'claims' => DB::table('business_claims')->join('businesses', 'businesses.id', '=', 'business_claims.business_id')
                ->join('users', 'users.id', '=', 'business_claims.user_id')->where('business_claims.status', 'pending')
                ->latest('business_claims.id')->paginate(15, ['business_claims.*', 'businesses.name as business_name', 'users.email as account_email'], 'claims_page'),
            'updates' => BusinessUpdate::with('branch.business')->where('status', 'pending')->latest()->paginate(15, ['*'], 'updates_page'),
            'imports' => DB::table('business_import_runs')->join('business_sources', 'business_sources.id', '=', 'business_import_runs.source_id')
                ->latest('business_import_runs.id')->paginate(15, ['business_import_runs.*', 'business_sources.name'], 'imports_page'),
        ]);
    }

    public function profile(Request $request, int $business)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($business, $request, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            ModerationDecision::requireCurrent('business', $item, $data['version']);
            abort_unless($item->status === 'pending' || ($item->status === 'active' && $data['decision'] === 'rejected'
                && ! $request->attributes->get('bulk_moderation')), 409);
            $item->update(['status' => $data['decision'] === 'approved' ? 'active' : 'rejected']);
            $event = 'business.'.$data['decision'];
            $audit = Audit::record($event, $request->user()->id, context: ['business' => $business]);
            ModerationDecision::record('business', $business, $data['decision'], $audit, $data, $audit);
            $locations = $item->locations()->pluck('location_id')->all();
            foreach ($item->members()->wherePivot('role', 'owner')->pluck('users.id') as $owner) {
                NotificationInbox::record($owner, $event, $audit, $business, $locations);
            }
        }, attempts: 3);

        return $this->success();
    }

    public function claim(Request $request, int $claim)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($claim, $request, $data) {
            $row = DB::table('business_claims')->where('id', $claim)->firstOrFail();
            $business = Business::lockForUpdate()->findOrFail($row->business_id);
            $row = DB::table('business_claims')->where('id', $claim)->lockForUpdate()->firstOrFail();
            ModerationDecision::requireCurrent('claim', $row, $data['version']);
            abort_unless($row->status === 'pending', 409);
            if ($data['decision'] === 'approved') {
                if ($business->status !== 'active' || $business->members()->wherePivot('role', 'owner')->lockForUpdate()->first(['users.id']) !== null
                    || $row->user_id === $request->user()->id) {
                    throw ValidationException::withMessages(['decision' => 'An independent reviewer can approve only unclaimed active profiles.']);
                }

                $business->members()->syncWithoutDetaching([$row->user_id => ['role' => 'owner']]);
            }
            DB::table('business_claims')->where('id', $claim)->update([
                'status' => $data['decision'], 'notes' => $data['notes'], 'reviewer_id' => $request->user()->id,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $audit = Audit::record('business.claim-'.$data['decision'], $request->user()->id, context: ['claim' => $claim, 'business' => $business->id]);
            ModerationDecision::record('claim', $claim, $data['decision'], $audit, $data, $audit);
            NotificationInbox::record($row->user_id, 'claim.'.$data['decision'], $audit, $claim, $business->locations()->pluck('location_id')->all());
        }, attempts: 3);

        return $this->success();
    }

    public function update(Request $request, int $update)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($update, $request, $data) {
            $initial = BusinessUpdate::findOrFail($update);
            $business = Business::lockForUpdate()->findOrFail($initial->branch->business_id);
            $branch = BusinessLocation::where('business_id', $business->id)->lockForUpdate()->findOrFail($initial->business_location_id);
            $item = BusinessUpdate::where('business_location_id', $branch->id)->lockForUpdate()->findOrFail($update);
            ModerationDecision::requireCurrent('business-update', $item, $data['version']);
            abort_unless($item->status === 'pending' || ($item->status === 'approved' && $data['decision'] === 'rejected'
                && ! $request->attributes->get('bulk_moderation')), 409);
            if ($data['decision'] === 'approved') {
                abort_unless($business->status === 'active' && $item->author_id !== null
                    && $business->members()->where('users.id', $item->author_id)
                        ->wherePivotIn('role', ['owner', 'manager', 'editor'])->lockForUpdate()->first(['users.id']) !== null,
                    409, 'The business or author is no longer eligible.');
            }
            $item->update(['status' => $data['decision'], 'published_at' => $data['decision'] === 'approved' ? now() : null]);
            $audit = Audit::record('business.update-'.$data['decision'], $request->user()->id, context: ['update' => $update]);
            ModerationDecision::record('business-update', $update, $data['decision'], $audit, $data, $audit);
            if ($item->author_id !== null) {
                NotificationInbox::record($item->author_id, 'business-update.'.$data['decision'], $audit, $update, [$branch->location_id]);
            }
        }, attempts: 3);

        return $this->success();
    }

    private function decision(Request $request): array
    {
        return $request->validate([
            'decision' => 'required|in:approved,rejected', 'notes' => 'required|string|min:8|max:1200',
            'reason' => 'required_if:decision,rejected|nullable|string|max:300',
            'version' => ModerationDecision::VERSION_RULES,
        ]);
    }

    private function success()
    {
        return redirect('/admin/business-review')->with('status', 'Review decision saved.');
    }
}
