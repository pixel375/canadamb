<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Location;
use App\Support\Audit;
use App\Support\BusinessClaims;
use App\Support\BusinessDirectory;
use App\Support\BusinessOwnership;
use App\Support\LocationScope;
use App\Support\ModerationDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessBranchController extends Controller
{
    private const ACTIONS = ['add', 'correct_address', 'temporarily_closed', 'closed', 'reopen'];

    public function index(Request $request, int $business, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $profile = $this->business($request, $business);
        abort_unless(BusinessOwnership::memberIsOwner($profile->id, $request->user()->id), 404);
        $branches = $profile->locations()->with('location')
            ->when($site->location_id !== null, fn ($q) => $q->where('location_id', $site->location_id))
            ->orderBy('id')->get();
        $places = $site->isNetwork() ? Location::selectable()->orderBy('name')->get() : collect();
        $requests = DB::table('business_branch_requests')->where('business_id', $business)
            ->latest('id')->paginate(15);
        $requests->getCollection()->each(function (object $change): void {
            $change->review_version = ModerationDecision::version('branch-request', $change);
        });

        return response()->view('business.branches', compact('profile', 'branches', 'places', 'requests'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function request(Request $request, int $business, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(self::ACTIONS)],
            'business_location_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'proposed_address' => 'nullable|string|min:3|max:255',
            'evidence' => 'required|string|min:30|max:2400',
        ]);
        DB::transaction(function () use ($request, $business, $site, $data): void {
            $profile = $this->business($request, $business, true);
            abort_unless(BusinessOwnership::memberIsOwner($business, $request->user()->id), 404);
            BusinessOwnership::requireUncontested($business);
            $action = $data['action'];
            $branch = null;
            if ($action === 'add') {
                $location = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
            } else {
                abort_unless(isset($data['business_location_id']), 422);
                $branch = $profile->locations()->when($site->location_id !== null,
                    fn ($q) => $q->where('location_id', $site->location_id))
                    ->lockForUpdate()->findOrFail($data['business_location_id']);
                $location = $branch->location_id;
            }
            $address = in_array($action, ['add', 'correct_address'], true)
                ? trim($data['proposed_address'] ?? '') : null;
            if (in_array($action, ['add', 'correct_address'], true)) {
                if (mb_strlen($address) < 3) {
                    throw ValidationException::withMessages(['proposed_address' => 'Enter the physical address before requesting review.']);
                }
                $same = DB::table('business_locations')->where([
                    'location_id' => $location,
                    'address_key' => BusinessDirectory::addressKey($profile->name, $address),
                ])->exists();
                if ($same || ($branch !== null && $branch->address === $address)) {
                    throw ValidationException::withMessages(['proposed_address' => 'This physical address is already listed or has not changed.']);
                }
            } else {
                $current = $branch->operational_status;
                if (($action === 'reopen' && $current === 'open')
                    || ($action === 'temporarily_closed' && $current !== 'open')
                    || ($action === 'closed' && $current === 'closed')) {
                    throw ValidationException::withMessages(['action' => 'This branch already has that status.']);
                }
            }
            if (DB::table('business_branch_requests')->where('business_id', $business)->where('status', 'pending')
                ->when($branch, fn ($q) => $q->where('business_location_id', $branch->id),
                    fn ($q) => $q->whereNull('business_location_id')->where('location_id', $location)
                        ->where('proposed_address', $address))
                ->exists()) {
                throw ValidationException::withMessages(['action' => 'There is already a pending review for this branch or address.']);
            }

            $id = DB::table('business_branch_requests')->insertGetId([
                'business_id' => $business, 'business_location_id' => $branch?->id,
                'location_id' => $location, 'requested_by_id' => $request->user()->id,
                'action' => $action, 'status' => 'pending',
                'proposed_address' => $address,
                'original_address' => $branch?->address, 'original_status' => $branch?->operational_status,
                'evidence' => BusinessClaims::seal($data['evidence']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.branch-change-requested', $request->user()->id, $site->id, [
                'business' => $business, 'request' => $id, 'action' => $action, 'branch' => $branch?->id,
            ]);
        }, attempts: 3);

        return redirect("/account/businesses/$business/branches")
            ->with('status', 'Branch change submitted for independent review. Nothing was published yet.');
    }

    public function withdraw(Request $request, int $business, int $change)
    {
        $data = $request->validate(['version' => ModerationDecision::VERSION_RULES]);
        DB::transaction(function () use ($request, $business, $change, $data): void {
            $this->business($request, $business, true);
            $item = DB::table('business_branch_requests')->where([
                'id' => $change, 'business_id' => $business, 'requested_by_id' => $request->user()->id,
            ])->lockForUpdate()->firstOrFail();
            ModerationDecision::requireCurrent('branch-request', $item, $data['version']);
            abort_unless($item->status === 'pending', 409);
            DB::table('business_branch_requests')->where('id', $change)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            Audit::record('business.branch-change-withdrawn', $request->user()->id, $request->attributes->get('site')->id, [
                'business' => $business, 'request' => $change,
            ]);
        }, attempts: 3);

        return redirect("/account/businesses/$business/branches")->with('status', 'Branch change withdrawn.');
    }

    private function business(Request $request, int $business, bool $lock = false): Business
    {
        $site = $request->attributes->get('site');

        return Business::whereKey($business)->where('status', 'active')
            ->when($site->location_id !== null, fn ($q) => $q->whereHas('locations',
                fn ($branches) => $branches->where('location_id', $site->location_id)))
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }
}
