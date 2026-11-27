<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessTeamController extends Controller
{
    public function index(Request $request, int $business)
    {
        $item = Business::managedBy($request->user()->id)->findOrFail($business);
        $members = $item->members()->orderBy('users.name')->get(['users.id', 'users.name']);
        $isOwner = $members->contains(fn ($member) => $member->id === $request->user()->id && $member->pivot->role === 'owner');

        return view('business.team', compact('item', 'members', 'isOwner'));
    }

    public function store(Request $request, int $business)
    {
        abort_if(config('network.business_access_requests'), 404);
        $data = $request->validate([
            'email' => 'required|email|max:254',
            'role' => 'required|in:manager,editor',
            'consent' => 'accepted',
        ]);
        DB::transaction(function () use ($request, $business, $data) {
            $item = $this->owner($request, $business);
            $member = User::where('email', $data['email'])->whereNotNull('email_verified_at')->first();
            if (! $member || $item->members()->whereKey($member->id)->exists() || $item->members()->count() >= 20) {
                throw ValidationException::withMessages(['email' => 'Unable to add this account. Check the email, verification status, existing membership and team size.']);
            }
            $item->members()->attach($member->id, ['role' => $data['role']]);
            Audit::record('business.member-added', $request->user()->id, context: ['business' => $business, 'member' => $member->id, 'role' => $data['role']]);
        }, attempts: 3);

        return $this->success($business);
    }

    public function update(Request $request, int $business, int $member)
    {
        DB::transaction(function () use ($request, $business, $member) {
            $item = $this->owner($request, $business);
            $target = $this->editableMember($item, $member);
            $data = $request->validate(['role' => 'required|in:manager,editor', 'expected_role' => 'required|in:manager,editor']);
            $this->checkRole($target, $data['expected_role']);
            if ($target->pivot->role === $data['role']) {
                return;
            }
            $item->members()->updateExistingPivot($member, ['role' => $data['role']]);
            Audit::record('business.member-role-changed', $request->user()->id, context: ['business' => $business, 'member' => $member, 'from' => $target->pivot->role, 'to' => $data['role']]);
        }, attempts: 3);

        return $this->success($business);
    }

    public function remove(Request $request, int $business, int $member)
    {
        DB::transaction(function () use ($request, $business, $member) {
            $item = $this->owner($request, $business);
            $target = $this->editableMember($item, $member);
            $data = $request->validate(['expected_role' => 'required|in:manager,editor']);
            $this->checkRole($target, $data['expected_role']);
            $item->members()->detach($member);
            Audit::record('business.member-removed', $request->user()->id, context: ['business' => $business, 'member' => $member]);
        }, attempts: 3);

        return $this->success($business);
    }

    public function leave(Request $request, int $business)
    {
        $data = $request->validate(['confirm_leave' => 'accepted', 'expected_role' => 'required|in:manager,editor']);
        $member = $request->user()->id;
        DB::transaction(function () use ($business, $member, $data) {
            $item = Business::lockForUpdate()->findOrFail($business);
            $target = $this->editableMember($item, $member);
            $this->checkRole($target, $data['expected_role']);
            $item->members()->detach($member);
            Audit::record('business.member-left', $member, context: ['business' => $business, 'role' => $target->pivot->role]);
        }, attempts: 3);

        return redirect('/account/businesses')->with('status', 'You left the business team. Your management access has ended.');
    }

    private function checkRole(User $member, string $expected): void
    {
        if ($member->pivot->role !== $expected) {
            throw ValidationException::withMessages(['expected_role' => 'This membership changed. Reload the team before continuing.']);
        }
    }

    private function owner(Request $request, int $business): Business
    {
        $item = Business::lockForUpdate()->findOrFail($business);
        abort_unless($item->status === 'active' && $item->members()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists(), 404);

        return $item;
    }

    private function editableMember(Business $item, int $member): User
    {
        return $item->members()->whereKey($member)->wherePivotIn('role', ['manager', 'editor'])->firstOrFail();
    }

    private function success(int $business)
    {
        return redirect('/account/businesses/'.$business.'/team')->with('status', 'Business team updated.');
    }
}
