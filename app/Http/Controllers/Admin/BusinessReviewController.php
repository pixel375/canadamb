<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessChanges;
use App\Support\BusinessInquirySettings;
use App\Support\KeywordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessReviewController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.business-review', [
            'businesses' => Business::with('locations.location')->where('status', 'pending')->latest()->paginate(15),
            'claims' => DB::table('business_claims')->join('businesses', 'businesses.id', '=', 'business_claims.business_id')
                ->join('users', 'users.id', '=', 'business_claims.user_id')->where('business_claims.status', 'pending')
                ->where('business_claims.user_id', '!=', $request->user()->id)
                ->whereNotExists(function ($query) use ($request) {
                    $query->selectRaw('1')->from('business_memberships')->whereColumn('business_memberships.business_id', 'business_claims.business_id')
                        ->where('business_memberships.user_id', $request->user()->id);
                })
                ->latest('business_claims.id')->paginate(15, ['business_claims.*', 'businesses.name', 'users.email'], 'claims_page'),
            'updates' => BusinessUpdate::with('branch.business')->where('status', 'pending')->latest()->paginate(15, ['*'], 'updates_page'),
            'imports' => DB::table('business_import_runs')->join('business_sources', 'business_sources.id', '=', 'business_import_runs.source_id')
                ->latest('business_import_runs.id')->paginate(15, ['business_import_runs.*', 'business_sources.name'], 'imports_page'),
        ]);
    }

    public function ownership(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'ownership' => 'nullable|in:claimed,unclaimed']);
        $items = Business::query()->with(['locations.location', 'members' => fn ($query) => $query->select('users.id', 'users.name')])
            ->when($filters['q'] ?? null, fn ($query, $term) => KeywordSearch::apply($query, $term, ['name', 'slug']))
            ->when(($filters['ownership'] ?? null) === 'claimed', fn ($query) => $query->whereHas('members', fn ($members) => $members->where('business_memberships.role', 'owner')))
            ->when(($filters['ownership'] ?? null) === 'unclaimed', fn ($query) => $query->whereDoesntHave('members', fn ($members) => $members->where('business_memberships.role', 'owner')))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('admin.business-ownership', compact('items', 'filters'));
    }

    public function profile(Request $request, int $business)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($business, $request, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            abort_unless($item->status === 'pending', 409);
            $item->update(['status' => $data['decision'] === 'approved' ? 'active' : 'rejected']);
            Audit::record('business.'.$data['decision'], $request->user()->id, context: ['business' => $business, 'notes' => $data['notes']]);
        });

        return $this->success();
    }

    public function claim(Request $request, int $claim)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($claim, $request, $data) {
            $row = DB::table('business_claims')->where('id', $claim)->firstOrFail();
            $business = Business::lockForUpdate()->findOrFail($row->business_id);
            $row = DB::table('business_claims')->where('id', $claim)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'pending', 409);
            abort_if((int) $row->user_id === $request->user()->id || $business->members()->whereKey($request->user()->id)->exists(), 403);
            if ($data['decision'] === 'approved') {
                if ($business->status !== 'active' || $business->members()->wherePivot('role', 'owner')->exists()
                    || ! User::whereKey($row->user_id)->whereNotNull('email_verified_at')->exists()) {
                    throw ValidationException::withMessages(['decision' => 'An independent reviewer can approve only unclaimed active profiles.']);
                }

                $business->members()->syncWithoutDetaching([$row->user_id => ['role' => 'owner']]);
                BusinessInquirySettings::reset($business->id);
                BusinessChanges::invalidateContact($business->id);
                $competing = DB::table('business_claims')->where('business_id', $business->id)->where('id', '!=', $claim)->where('status', 'pending');
                $count = $competing->update(['status' => 'superseded', 'updated_at' => now()]);
                if ($count) {
                    Audit::record('business.claims-superseded', $request->user()->id, context: ['business' => $business->id, 'count' => $count]);
                }
            }
            DB::table('business_claims')->where('id', $claim)->update([
                'status' => $data['decision'], 'notes' => $data['notes'], 'reviewer_id' => $request->user()->id,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.claim-'.$data['decision'], $request->user()->id, context: ['claim' => $claim, 'business' => $business->id]);
        });

        return $this->success();
    }

    public function update(Request $request, int $update)
    {
        $data = $this->decision($request);
        DB::transaction(function () use ($update, $request, $data) {
            $initial = BusinessUpdate::with('branch')->findOrFail($update);
            $business = Business::lockForUpdate()->findOrFail($initial->branch->business_id);
            $item = BusinessUpdate::with('branch')->lockForUpdate()->findOrFail($update);
            abort_unless($item->status === 'pending', 409);
            abort_if($item->author_id === $request->user()->id || $business->members()->whereKey($request->user()->id)->exists(), 403);
            abort_if($data['decision'] === 'approved' && (! $item->branch->isOpen() || $business->status !== 'active'), 409);
            $item->update(['status' => $data['decision'], 'published_at' => $data['decision'] === 'approved' ? now() : null]);
            Audit::record('business.update-'.$data['decision'], $request->user()->id, context: ['update' => $update, 'notes' => $data['notes']]);
        });

        return $this->success();
    }

    private function decision(Request $request): array
    {
        return $request->validate(['decision' => 'required|in:approved,rejected', 'notes' => 'required|string|min:8|max:1200']);
    }

    private function success()
    {
        return redirect('/admin/business-review')->with('status', 'Review decision saved.');
    }
}
