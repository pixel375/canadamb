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
        $data = $request->validate(['role' => 'required|in:manager,editor']);
        DB::transaction(function () use ($request, $business, $member, $data) {
            $item = $this->owner($request, $business);
            $target = $this->editableMember($item, $member);
            $item->members()->updateExistingPivot($member, ['role' => $data['role']]);
            Audit::record('business.member-role-changed', $request->user()->id, context: ['business' => $business, 'member' => $member, 'from' => $target->pivot->role, 'to' => $data['role']]);
        }, attempts: 3);

        return $this->success($business);
    }

    public function remove(Request $request, int $business, int $member)
    {
        DB::transaction(function () use ($request, $business, $member) {
            $item = $this->owner($request, $business);
            $this->editableMember($item, $member);
            $item->members()->detach($member);
            Audit::record('business.member-removed', $request->user()->id, context: ['business' => $business, 'member' => $member]);
        }, attempts: 3);

        return $this->success($business);
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
