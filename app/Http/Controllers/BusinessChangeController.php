<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Location;
use App\Support\Audit;
use App\Support\BusinessChanges;
use App\Support\BusinessDirectory;
use App\Support\LocationScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessChangeController extends Controller
{
    public function index(Request $request, int $business)
    {
        $item = $this->managed($request, $business);
        $site = $request->attributes->get('site');
        $branches = $item->locations()->with('location')->when($site->location_id, fn ($query, $id) => $query->where('location_id', $id))->get();
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $isOwner = $item->members()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists();
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,withdrawn,stale',
            'kind' => 'nullable|in:branch-add,branch-address,branch-close,branch-reopen,branch-relocate,contact', 'reference' => 'nullable|integer|min:1']);
        $items = DB::table('business_change_requests')->where('business_id', $business)
            ->when($site->location_id, fn ($query, $id) => $query->where(fn ($scope) => $scope->where('location_id', $id)->orWhereNull('location_id')))
            ->when(! $isOwner, fn ($query) => $query->where('kind', '!=', 'contact'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('id', $reference))
            ->latest('id')->paginate(20)->withQueryString();

        return view('business.changes', compact('item', 'branches', 'places', 'isOwner', 'items', 'filters'));
    }

    public function store(Request $request, int $business)
    {
        $this->managed($request, $business);
        $data = $request->validate([
            'kind' => 'required|in:branch-add,branch-address,branch-close,branch-reopen,branch-relocate,contact',
            'branch_id' => 'nullable|required_if:kind,branch-address,branch-close,branch-reopen,branch-relocate|integer',
            'location_id' => 'nullable|required_if:kind,branch-relocate|integer',
            'address' => 'nullable|required_if:kind,branch-add,branch-address,branch-relocate|string|max:255',
            'reason' => 'nullable|required_if:kind,branch-close,branch-reopen|string|min:10|max:255',
        ]);
        DB::transaction(function () use ($request, $business, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            $this->managed($request, $business);
            $id = $request->user()->id;
            $site = $request->attributes->get('site');
            $branch = null;
            $place = null;
            $previous = null;
            if ($data['kind'] === 'contact') {
                abort_unless($item->members()->whereKey($id)->wherePivot('role', 'owner')->exists(), 404);
                if (! filter_var($item->public_email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['kind' => 'Save a valid public contact email before requesting verification.']);
                }
                $value = $item->public_email;
            } elseif (in_array($data['kind'], ['branch-close', 'branch-reopen', 'branch-relocate'], true)) {
                abort_unless($item->members()->whereKey($id)->wherePivot('role', 'owner')->exists(), 404);
                $branch = $item->locations()->findOrFail($data['branch_id']);
                abort_unless($branch->isOpen() === ($data['kind'] !== 'branch-reopen'), 409);
                $previous = $branch->address;
                if (in_array($data['kind'], ['branch-close', 'branch-reopen'], true)) {
                    $place = LocationScope::forPublication($site, $branch->location_id);
                    $value = trim($data['reason']);
                } else {
                    abort_unless($site->isNetwork(), 404);
                    $place = LocationScope::forPublication($site, (int) $data['location_id']);
                    if ($place === $branch->location_id) {
                        throw ValidationException::withMessages(['location_id' => 'Use address correction for the same community.']);
                    }
                    $value = trim($data['address']);
                }
            } elseif ($data['kind'] === 'branch-address') {
                $branch = $item->openLocations()->findOrFail($data['branch_id']);
                $place = LocationScope::forPublication($site, $branch->location_id);
                $previous = $branch->address;
                $value = trim($data['address']);
                if ($previous === $value) {
                    throw ValidationException::withMessages(['address' => 'Enter a changed address.']);
                }
            } else {
                $place = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
                $value = trim($data['address']);
            }
            if ($place && ! in_array($data['kind'], ['branch-close', 'branch-reopen'], true) && ($value === '' || $this->addressConflict($item, $place, $value, $branch?->id))) {
                throw ValidationException::withMessages(['address' => 'This business address already appears in the directory or is empty.']);
            }
            $pending = DB::table('business_change_requests')->where('business_id', $business)
                ->when($branch, fn ($query) => $query->whereIn('kind', ['branch-address', 'branch-close', 'branch-reopen', 'branch-relocate']),
                    fn ($query) => $query->where('kind', $data['kind']))
                ->where('status', 'pending')
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->when($data['kind'] === 'branch-add', fn ($query) => $query->where('location_id', $place)->where('value', $value))
                ->exists();
            if ($pending) {
                throw ValidationException::withMessages(['kind' => 'A matching request already awaits review.']);
            }
            if ($data['kind'] === 'contact' && DB::table('business_change_requests')->where('business_id', $business)
                ->where('kind', 'contact')->where('status', 'approved')->where('value', $value)->exists()) {
                throw ValidationException::withMessages(['kind' => 'This contact email has already been reviewed.']);
            }
            $change = DB::table('business_change_requests')->insertGetId([
                'business_id' => $business, 'requester_id' => $id, 'branch_id' => $branch?->id, 'location_id' => $place,
                'kind' => $data['kind'], 'value' => $value, 'previous_value' => $previous,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.change-requested', $id, context: ['business' => $business, 'change' => $change]);
        }, attempts: 3);

        return $this->done($business);
    }

    public function withdraw(Request $request, int $business, int $change)
    {
        DB::transaction(function () use ($request, $business, $change) {
            Business::lockForUpdate()->findOrFail($business);
            $this->managed($request, $business);
            $site = $request->attributes->get('site');
            $row = DB::table('business_change_requests')->where('business_id', $business)->where('id', $change)
                ->when($site->location_id, fn ($query, $id) => $query->where(fn ($scope) => $scope->where('location_id', $id)->orWhereNull('location_id')))
                ->where('requester_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'pending', 409);
            $this->decision($change, 'withdrawn', $request->user()->id, $business);
        }, attempts: 3);

        return $this->done($business);
    }

    public function queue(Request $request)
    {
        abort_unless(BusinessChanges::enabled(), 404);
        $id = $request->user()->id;
        $filters = $request->validate(['status' => 'nullable|in:pending,approved,rejected,withdrawn,stale', 'reference' => 'nullable|integer|min:1', 'kind' => 'nullable|in:branch-add,branch-address,branch-close,branch-reopen,branch-relocate,contact']);
        $items = DB::table('business_change_requests')
            ->join('businesses', 'businesses.id', '=', 'business_change_requests.business_id')
            ->leftJoin('locations', 'locations.id', '=', 'business_change_requests.location_id')
            ->where('business_change_requests.status', $filters['status'] ?? 'pending')
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('business_change_requests.id', $reference))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('business_change_requests.kind', $kind))
            ->where('requester_id', '!=', $id)
            ->whereNotExists(function ($query) use ($id) {
                $query->selectRaw('1')->from('business_memberships')
                    ->whereColumn('business_memberships.business_id', 'business_change_requests.business_id')
                    ->where('user_id', $id);
            })
            ->latest('business_change_requests.id')->paginate(20, ['business_change_requests.*', 'businesses.name as business_name', 'locations.name as location_name'])->withQueryString();

        return view('admin.business-changes', compact('items', 'filters'));
    }

    public function review(Request $request, int $change)
    {
        abort_unless(BusinessChanges::enabled(), 404);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'note' => 'required|string|min:10|max:1000']);
        try {
            DB::transaction(function () use ($request, $change, $data) {
                $initial = DB::table('business_change_requests')->where('id', $change)->firstOrFail();
                $business = Business::lockForUpdate()->findOrFail($initial->business_id);
                $row = DB::table('business_change_requests')->where('id', $change)->lockForUpdate()->firstOrFail();
                $id = $request->user()->id;
                abort_if((int) $row->requester_id === $id || $business->members()->whereKey($id)->exists(), 403);
                abort_unless($row->status === 'pending', 409);
                if ($data['decision'] === 'approved') {
                    $this->apply($business, $row);
                }
                DB::table('business_change_requests')->where('id', $change)->update([
                    'reviewer_id' => $id, 'review_note' => $data['note'], 'reviewed_at' => now(),
                ]);
                $this->decision($change, $data['decision'], $id, $business->id);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['decision' => 'The address now conflicts with an existing directory entry.']);
        }

        return redirect('/admin/business-changes')->with('status', 'Business change review saved.');
    }

    private function apply(Business $business, object $row): void
    {
        $roles = in_array($row->kind, ['contact', 'branch-close', 'branch-reopen', 'branch-relocate'], true) ? ['owner'] : ['owner', 'manager'];
        abort_unless($business->status === 'active' && $row->requester_id
            && $business->members()->whereKey($row->requester_id)->wherePivotIn('role', $roles)->exists(), 409);
        if ($row->kind === 'contact') {
            abort_unless($business->public_email === $row->value, 409);

            return;
        }
        abort_unless(Location::selectable()->whereKey($row->location_id)->exists(), 409);
        $branch = $row->branch_id ? $business->locations()->lockForUpdate()->findOrFail($row->branch_id) : null;
        if ($branch) {
            abort_unless($branch->isOpen() === ($row->kind !== 'branch-reopen'), 409);
        }
        abort_unless(in_array($row->kind, ['branch-add', 'branch-address', 'branch-close', 'branch-reopen', 'branch-relocate'], true), 409);
        if ($row->kind === 'branch-reopen') {
            abort_unless($branch && $branch->address === $row->previous_value && (int) $branch->location_id === (int) $row->location_id, 409);
            abort_if($this->addressConflict($business, (int) $row->location_id, $branch->address, $branch->id), 409);
            $branch->update(['location_status' => 'user_selected', 'last_verified_at' => now()]);

            return;
        }
        if ($row->kind === 'branch-close') {
            abort_unless($branch && $branch->address === $row->previous_value && (int) $branch->location_id === (int) $row->location_id, 409);
            $this->closeBranch($branch, (int) $row->id);

            return;
        }
        if ($row->kind === 'branch-relocate') {
            abort_unless($branch && $branch->address === $row->previous_value && (int) $branch->location_id !== (int) $row->location_id, 409);
            abort_if($this->addressConflict($business, (int) $row->location_id, $row->value, null), 409);
            $business->locations()->create([
                'location_id' => $row->location_id, 'address' => $row->value,
                'address_key' => BusinessDirectory::addressKey($business->name, $row->value), 'last_verified_at' => now(),
            ]);
            $this->closeBranch($branch, (int) $row->id);

            return;
        }
        if ($row->kind === 'branch-address') {
            abort_unless($branch && $branch->address === $row->previous_value && (int) $branch->location_id === (int) $row->location_id, 409);
        }
        abort_if($this->addressConflict($business, (int) $row->location_id, $row->value, $branch?->id), 409);
        $changes = [
            'address' => $row->value, 'address_key' => BusinessDirectory::addressKey($business->name, $row->value),
            'last_verified_at' => now(),
        ];
        if ($branch) {
            $branch->update($changes);
        } else {
            $business->locations()->create($changes + ['location_id' => $row->location_id]);
        }
    }

    private function closeBranch(BusinessLocation $branch, int $request): void
    {
        // Preserve the historic location ID, address and source identity. Never
        // move jobs, reports, updates or inquiry history to a different branch.
        $branch->update(['location_status' => 'closed', 'last_verified_at' => now()]);
        DB::table('job_postings')->where('business_location_id', $branch->id)->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'withdrawn', 'updated_at' => now()]);
        DB::table('business_updates')->where('business_location_id', $branch->id)->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'withdrawn', 'updated_at' => now()]);
        DB::table('business_change_requests')->where('branch_id', $branch->id)->where('id', '!=', $request)
            ->where('status', 'pending')->update(['status' => 'stale', 'updated_at' => now()]);
    }

    private function addressConflict(Business $business, int $place, string $address, ?int $except): bool
    {
        $key = BusinessDirectory::addressKey($business->name, $address);

        return DB::table('business_locations')->where('location_id', $place)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->where(fn ($query) => $query->where('address_key', $key)->orWhere(fn ($same) => $same->where('business_id', $business->id)->where('address', $address)))
            ->exists();
    }

    private function managed(Request $request, int $business): Business
    {
        abort_unless(BusinessChanges::enabled(), 404);

        return Business::managedBy($request->user()->id, ['owner', 'manager'])->where('status', 'active')->findOrFail($business);
    }

    private function decision(int $change, string $status, int $actor, int $business): void
    {
        DB::table('business_change_requests')->where('id', $change)->update(['status' => $status, 'updated_at' => now()]);
        Audit::record('business.change-'.$status, $actor, context: ['change' => $change, 'business' => $business]);
    }

    private function done(int $business)
    {
        return redirect('/account/businesses/'.$business.'/changes')->with('status', 'Business change request updated.');
    }
}
