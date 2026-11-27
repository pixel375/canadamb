<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessChanges;
use App\Support\BusinessInquirySettings;
use App\Support\KeywordSearch;
use App\Support\OwnerNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessReviewController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'location' => 'nullable|integer|exists:locations,id',
            'history' => 'nullable|in:pending,completed']);
        $history = ($filters['history'] ?? 'pending') === 'completed';

        return view('admin.business-review', [
            'filters' => $filters, 'places' => Location::selectable()->orderBy('name')->get(['id', 'name']), 'history' => $history,
            'businesses' => Business::with('locations.location')->whereIn('status', $history ? ['active', 'rejected'] : ['pending'])
                ->when($filters['location'] ?? null, fn ($query, $id) => $query->whereHas('locations', fn ($branch) => $branch->where('location_id', $id)))
                ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['name', 'description']))
                ->whereDoesntHave('members', fn ($members) => $members->whereKey($request->user()->id))->latest()->latest('id')->paginate(15)->withQueryString(),
            'claims' => DB::table('business_claims')->join('businesses', 'businesses.id', '=', 'business_claims.business_id')
                ->join('users', 'users.id', '=', 'business_claims.user_id')->whereIn('business_claims.status', $history ? ['approved', 'rejected', 'withdrawn', 'superseded'] : ['pending'])
                ->when($filters['location'] ?? null, fn ($query, $id) => $query->whereExists(fn ($branch) => $branch->selectRaw('1')->from('business_locations')->whereColumn('business_locations.business_id', 'business_claims.business_id')->where('location_id', $id)))
                ->when(filled($filters['q'] ?? null), function ($query) use ($filters) {
                    foreach (KeywordSearch::patterns($filters['q']) as $pattern) {
                        $query->whereRaw("LOWER(businesses.name) LIKE ? ESCAPE '!'", [$pattern]);
                    }
                })
                ->where('business_claims.user_id', '!=', $request->user()->id)
                ->whereNotExists(function ($query) use ($request) {
                    $query->selectRaw('1')->from('business_memberships')->whereColumn('business_memberships.business_id', 'business_claims.business_id')
                        ->where('business_memberships.user_id', $request->user()->id);
                })
                ->latest('business_claims.id')->paginate(15, $history ? ['business_claims.id', 'business_claims.status', 'businesses.name', 'business_claims.created_at'] : ['business_claims.*', 'businesses.name', 'users.email'], 'claims_page')->withQueryString(),
            'updates' => BusinessUpdate::with('branch.business', 'branch.location')->whereIn('status', $history ? ['approved', 'rejected', 'withdrawn'] : ['pending'])
                ->when($filters['location'] ?? null, fn ($query, $id) => $query->whereHas('branch', fn ($branch) => $branch->where('location_id', $id)))
                ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'body']))
                ->where(fn ($query) => $query->whereNull('author_id')->orWhere('author_id', '!=', $request->user()->id))
                ->whereHas('branch.business', fn ($query) => $query->whereDoesntHave('members', fn ($members) => $members->whereKey($request->user()->id)))
                ->latest()->latest('id')->paginate(15, ['*'], 'updates_page')->withQueryString(),
            'imports' => DB::table('business_import_runs')->join('business_sources', 'business_sources.id', '=', 'business_import_runs.source_id')
                ->latest('business_import_runs.id')->paginate(15, ['business_import_runs.*', 'business_sources.name'], 'imports_page')->withQueryString(),
        ]);
    }

    public function ownership(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'ownership' => 'nullable|in:claimed,unclaimed,ambiguous',
            'status' => 'nullable|in:active,pending,rejected', 'location' => 'nullable|integer|exists:locations,id', 'reference' => 'nullable|integer|min:1',
            'member' => 'nullable|integer|min:1']);
        $places = Location::selectable()->orderBy('name')->get(['id', 'name']);
        $items = Business::query()->with(['locations.location', 'members' => fn ($query) => $query->select('users.id', 'users.name')])
            ->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['name', 'slug']))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['location'] ?? null, fn ($query, $id) => $query->whereHas('locations', fn ($branch) => $branch->where('location_id', $id)))
            ->when($filters['reference'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['member'] ?? null, fn ($query, $id) => $query->whereHas('members', fn ($members) => $members->whereKey($id)))
            ->when(($filters['ownership'] ?? null) === 'ambiguous', fn ($query) => $query->whereHas('members', fn ($members) => $members->where('business_memberships.role', 'owner'), '>', 1))
            ->when(($filters['ownership'] ?? null) === 'claimed', fn ($query) => $query->whereHas('members', fn ($members) => $members->where('business_memberships.role', 'owner')))
            ->when(($filters['ownership'] ?? null) === 'unclaimed', fn ($query) => $query->whereDoesntHave('members', fn ($members) => $members->where('business_memberships.role', 'owner')))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('admin.business-ownership', compact('items', 'filters', 'places'));
    }

    public function profile(Request $request, int $business)
    {
        $data = $this->decision($request) + $request->validate(['version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($business, $request, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            abort_if($item->members()->whereKey($request->user()->id)->exists(), 403);
            abort_unless($item->status === 'pending', 409);
            if (isset($data['version'])) {
                abort_unless(hash_equals($item->profileVersion(), $data['version']), 409, 'This profile changed. Reload before reviewing.');
            }
            $item->update(['status' => $data['decision'] === 'approved' ? 'active' : 'rejected']);
            if (OwnerNotifications::enabled()) {
                DB::table('business_memberships')->where('business_id', $item->id)->where('role', 'owner')
                    ->select('user_id')->chunkById(100, function ($owners) use ($item, $data) {
                        foreach ($owners as $owner) {
                            OwnerNotifications::record($owner->user_id, 'business-profile', $item->id, $data['decision']);
                        }
                    }, 'user_id');
            }
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
                if (OwnerNotifications::enabled()) {
                    (clone $competing)->select('id', 'user_id')->chunkById(100, function ($claims) {
                        foreach ($claims as $pendingClaim) {
                            OwnerNotifications::record($pendingClaim->user_id, 'business-claim', $pendingClaim->id, 'superseded');
                        }
                    });
                }
                $count = $competing->update(['status' => 'superseded', 'updated_at' => now()]);
                if ($count) {
                    Audit::record('business.claims-superseded', $request->user()->id, context: ['business' => $business->id, 'count' => $count]);
                }
            }
            DB::table('business_claims')->where('id', $claim)->update([
                'status' => $data['decision'], 'notes' => $data['notes'], 'reviewer_id' => $request->user()->id,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            OwnerNotifications::record($row->user_id, 'business-claim', $claim, $data['decision']);
            Audit::record('business.claim-'.$data['decision'], $request->user()->id, context: ['claim' => $claim, 'business' => $business->id]);
        });

        return $this->success();
    }

    public function update(Request $request, int $update)
    {
        $data = $this->decision($request) + $request->validate(['version' => 'required|string|size:64']);
        DB::transaction(function () use ($update, $request, $data) {
            $initial = BusinessUpdate::with('branch')->findOrFail($update);
            $business = Business::lockForUpdate()->findOrFail($initial->branch->business_id);
            $item = BusinessUpdate::with('branch')->lockForUpdate()->findOrFail($update);
            abort_unless($item->status === 'pending', 409);
            abort_if($item->author_id === $request->user()->id || $business->members()->whereKey($request->user()->id)->exists(), 403);
            abort_unless(hash_equals($item->reviewVersion(), $data['version']), 409, 'This update changed. Reload before reviewing.');
            abort_if($data['decision'] === 'approved' && (! $item->branch->isOpen() || $business->status !== 'active'), 409);
            $item->update(['status' => $data['decision'], 'published_at' => $data['decision'] === 'approved' ? now() : null]);
            OwnerNotifications::record($item->author_id, 'business-update', $update, $data['decision']);
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
