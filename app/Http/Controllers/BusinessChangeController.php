<?php

namespace App\Http\Controllers;

use App\Models\Business;
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
        $items = DB::table('business_change_requests')->where('business_id', $business)
            ->when($site->location_id, fn ($query, $id) => $query->where(fn ($scope) => $scope->where('location_id', $id)->orWhereNull('location_id')))
            ->when(! $isOwner, fn ($query) => $query->where('kind', '!=', 'contact'))
            ->latest('id')->paginate(20);

        return view('business.changes', compact('item', 'branches', 'places', 'isOwner', 'items'));
    }

    public function store(Request $request, int $business)
    {
        $this->managed($request, $business);
        $data = $request->validate([
            'kind' => 'required|in:branch-add,branch-address,contact',
            'branch_id' => 'nullable|required_if:kind,branch-address|integer',
            'location_id' => 'nullable|integer',
            'address' => 'nullable|required_if:kind,branch-add,branch-address|string|max:255',
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
            } elseif ($data['kind'] === 'branch-address') {
                $branch = $item->locations()->findOrFail($data['branch_id']);
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
            if ($place && ($value === '' || $this->addressConflict($item, $place, $value, $branch?->id))) {
                throw ValidationException::withMessages(['address' => 'This business address already appears in the directory or is empty.']);
            }
            $pending = DB::table('business_change_requests')->where('business_id', $business)
                ->where('kind', $data['kind'])->where('status', 'pending')
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
            $row = DB::table('business_change_requests')->where('business_id', $business)->where('id', $change)
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
        $items = DB::table('business_change_requests')
            ->join('businesses', 'businesses.id', '=', 'business_change_requests.business_id')
            ->leftJoin('locations', 'locations.id', '=', 'business_change_requests.location_id')
            ->where('business_change_requests.status', 'pending')
            ->where('requester_id', '!=', $id)
            ->whereNotExists(function ($query) use ($id) {
                $query->selectRaw('1')->from('business_memberships')
                    ->whereColumn('business_memberships.business_id', 'business_change_requests.business_id')
                    ->where('user_id', $id);
            })
            ->latest('business_change_requests.id')->paginate(20, ['business_change_requests.*', 'businesses.name as business_name', 'locations.name as location_name']);

        return view('admin.business-changes', compact('items'));
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
        $roles = $row->kind === 'contact' ? ['owner'] : ['owner', 'manager'];
        abort_unless($business->status === 'active' && $row->requester_id
            && $business->members()->whereKey($row->requester_id)->wherePivotIn('role', $roles)->exists(), 409);
        if ($row->kind === 'contact') {
            abort_unless($business->public_email === $row->value, 409);

            return;
        }
        abort_unless(Location::selectable()->whereKey($row->location_id)->exists(), 409);
        $branch = $row->branch_id ? $business->locations()->lockForUpdate()->findOrFail($row->branch_id) : null;
        abort_unless(in_array($row->kind, ['branch-add', 'branch-address'], true), 409);
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
