<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessClaims;
use App\Support\BusinessDirectory;
use App\Support\BusinessOwnership;
use App\Support\ModerationDecision;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessOwnershipController extends Controller
{
    public function index(Request $request)
    {
        $member = $request->user()->id;
        $site = $request->attributes->get('site');
        $items = DB::table('business_ownership_cases as ownership')
            ->join('businesses as business', 'business.id', '=', 'ownership.business_id')
            ->where(fn (Builder $query) => $query->where('ownership.initiated_by_id', $member)
                ->orWhere('ownership.proposed_owner_id', $member))
            ->when($site->location_id !== null, fn (Builder $query) => $query->whereExists(
                fn (Builder $local) => $local->selectRaw('1')->from('business_locations as branch')
                    ->whereColumn('branch.business_id', 'business.id')
                    ->where('branch.location_id', $site->location_id)))
            ->select('ownership.*', 'business.name as business_name')
            ->orderByDesc('ownership.id')->paginate(20);

        return response()->view('business.ownership-cases', compact('items', 'member'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function disputeForm(Request $request, string $slug, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $business = $directory->visible($site)->where('slug', $slug)->firstOrFail();
        abort_unless(DB::table('business_memberships')->where(['business_id' => $business->id, 'role' => 'owner'])->exists(), 404);

        return response()->view('business.ownership-dispute', compact('business'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function dispute(Request $request, string $slug, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $data = $request->validate(['evidence' => 'required|string|min:30|max:2400']);
        DB::transaction(function () use ($request, $site, $slug, $data, $directory): void {
            $business = $directory->visible($site)->where('slug', $slug)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('business_memberships')->where([
                'business_id' => $business->id, 'role' => 'owner',
            ])->exists(), 409);
            if (BusinessOwnership::inReview($business->id)) {
                throw ValidationException::withMessages(['evidence' => 'A review is already in progress for this business.']);
            }
            $id = DB::table('business_ownership_cases')->insertGetId([
                'business_id' => $business->id, 'type' => 'dispute', 'status' => 'pending_review',
                'initiated_by_id' => $request->user()->id, 'evidence' => BusinessClaims::seal($data['evidence']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.ownership-dispute-submitted', $request->user()->id, $site->id, [
                'case' => $id, 'business' => $business->id,
            ]);
        }, attempts: 3);

        return redirect('/account/ownership-cases')->with('status', 'Your private ownership concern was sent for independent review.');
    }

    public function transfer(Request $request, int $business, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $data = $request->validate([
            'proposed_owner_id' => 'required|integer|min:1',
            'evidence' => 'required|string|min:30|max:2400',
        ]);
        DB::transaction(function () use ($request, $site, $business, $data): void {
            $profile = Business::whereKey($business)->where('status', 'active')->lockForUpdate()->firstOrFail();
            abort_unless(BusinessOwnership::belongsToPortal($business, $site), 404);
            abort_unless(BusinessOwnership::memberIsOwner($business, $request->user()->id), 404);
            BusinessOwnership::requireUncontested($business);
            $successor = User::whereKey($data['proposed_owner_id'])->first();
            if ($successor === null || ! $successor->hasVerifiedEmail() || $successor->id === $request->user()->id
                || BusinessOwnership::memberIsOwner($business, $successor->id)) {
                throw ValidationException::withMessages(['proposed_owner_id' => 'Choose another verified member who is not already an owner.']);
            }
            if (DB::table('business_ownership_cases')->where([
                'business_id' => $profile->id, 'type' => 'transfer',
            ])->whereIn('status', BusinessOwnership::TRANSFER_PENDING)->exists()) {
                throw ValidationException::withMessages(['proposed_owner_id' => 'An ownership transfer is already awaiting acceptance or review.']);
            }
            $id = DB::table('business_ownership_cases')->insertGetId([
                'business_id' => $business, 'type' => 'transfer', 'status' => 'awaiting_acceptance',
                'initiated_by_id' => $request->user()->id,
                'outgoing_owner_id' => $request->user()->id, 'proposed_owner_id' => $successor->id,
                'evidence' => BusinessClaims::seal($data['evidence']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.ownership-transfer-proposed', $request->user()->id, $site->id, [
                'case' => $id, 'business' => $business, 'successor' => $successor->id,
            ]);
        }, attempts: 3);

        return redirect('/account/ownership-cases')->with('status', 'Your proposed successor must accept before an independent reviewer can consider the transfer.');
    }

    public function respond(Request $request, int $case)
    {
        $data = $request->validate([
            'action' => 'required|in:accept,decline,withdraw',
            'version' => ModerationDecision::VERSION_RULES,
        ]);
        DB::transaction(function () use ($request, $case, $data): void {
            $initial = DB::table('business_ownership_cases')->find($case) ?? abort(404);
            $business = Business::lockForUpdate()->findOrFail($initial->business_id);
            abort_unless(BusinessOwnership::belongsToPortal($business->id, $request->attributes->get('site')), 404);
            $item = DB::table('business_ownership_cases')->where('id', $case)->lockForUpdate()->firstOrFail();
            ModerationDecision::requireCurrent('ownership-case', $item, $data['version']);
            $actor = $request->user()->id;
            if ($data['action'] === 'withdraw') {
                abort_unless($actor === $item->initiated_by_id && in_array($item->status, ['awaiting_acceptance', 'pending_review'], true), 403);
                $next = 'withdrawn';
            } else {
                abort_unless($item->type === 'transfer' && $item->status === 'awaiting_acceptance'
                    && $item->proposed_owner_id === $actor, 403);
                abort_unless($request->user()->hasVerifiedEmail() && $business->status === 'active', 409);
                BusinessOwnership::requireUncontested($business->id);
                abort_unless(BusinessOwnership::memberIsOwner($business->id, $item->outgoing_owner_id), 409);
                abort_unless(! BusinessOwnership::memberIsOwner($business->id, $actor), 409);
                $next = $data['action'] === 'accept' ? 'pending_review' : 'declined';
            }
            DB::table('business_ownership_cases')->where('id', $case)->update([
                'status' => $next, 'accepted_at' => $next === 'pending_review' ? now() : $item->accepted_at,
                'updated_at' => now(),
            ]);
            Audit::record('business.ownership-'.$next, $actor, $request->attributes->get('site')->id, [
                'case' => $case, 'business' => $business->id,
            ]);
        }, attempts: 3);

        return redirect('/account/ownership-cases')->with('status', 'Your response was recorded.');
    }
}
