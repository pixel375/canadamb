<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDirectory;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessTeamController extends Controller
{
    private const STAFF = ['manager', 'editor'];

    public function index(Request $request, int $business, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $item = $this->visibleBusiness($request, $business);
        $this->requireOwner($item->id, $request->user()->id);
        $members = DB::table('business_memberships as member')
            ->join('users', 'users.id', '=', 'member.user_id')
            ->where('member.business_id', $item->id)
            ->orderBy('member.role')->orderBy('users.id')
            ->get(['users.id', 'users.name', 'member.role']);
        $invitations = $this->pending($item->id)->join('users as recipient', 'recipient.id', '=', 'business_team_invitations.invited_user_id')
            ->orderByDesc('business_team_invitations.id')
            ->get(['business_team_invitations.id', 'business_team_invitations.role', 'business_team_invitations.expires_at', 'recipient.name']);

        return response()->view('business.team', compact('item', 'members', 'invitations'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function invitations(Request $request)
    {
        $items = DB::table('business_team_invitations as invitation')
            ->join('businesses as business', 'business.id', '=', 'invitation.business_id')
            ->where('invitation.invited_user_id', $request->user()->id)
            ->where('invitation.status', 'pending')
            ->where('invitation.expires_at', '>', now())
            ->where('business.status', 'active')
            ->whereExists(fn (Builder $owners) => $owners->selectRaw('1')
                ->from('business_memberships as owner')->whereColumn('owner.business_id', 'business.id')
                ->where('owner.role', 'owner'))
            ->orderByDesc('invitation.id')
            ->get(['invitation.id', 'invitation.role', 'invitation.expires_at', 'business.name']);

        return response()->view('business.invitations', compact('items'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function invite(Request $request, int $business, BusinessDirectory $directory)
    {
        $site = $request->attributes->get('site');
        abort_unless($directory->enabled($site), 404);
        $data = $request->validate([
            'invited_user_id' => 'required|integer|min:1',
            'role' => ['required', Rule::in(self::STAFF)],
        ]);
        DB::transaction(function () use ($request, $business, $data): void {
            $item = $this->visibleBusiness($request, $business, true);
            $this->requireOwner($item->id, $request->user()->id);
            $recipient = User::find($data['invited_user_id']);
            if ($recipient === null || ! $recipient->hasVerifiedEmail() || $recipient->id === $request->user()->id) {
                throw ValidationException::withMessages(['invited_user_id' => 'Choose a different verified member account.']);
            }
            if ($this->membership($item->id, $recipient->id)->exists()) {
                throw ValidationException::withMessages(['invited_user_id' => 'This member already represents this business.']);
            }
            if ($this->pending($item->id)->where('invited_user_id', $recipient->id)->exists()) {
                throw ValidationException::withMessages(['invited_user_id' => 'This member already has an invitation.']);
            }
            $id = DB::table('business_team_invitations')->insertGetId([
                'business_id' => $item->id, 'invited_by_id' => $request->user()->id,
                'invited_user_id' => $recipient->id, 'role' => $data['role'],
                'status' => 'pending', 'expires_at' => now()->addDays(7),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.team-invited', $request->user()->id, $request->attributes->get('site')->id, [
                'business' => $item->id, 'invitation' => $id, 'invitee' => $recipient->id, 'role' => $data['role'],
            ]);
        }, attempts: 3);

        return redirect("/account/businesses/$business/team")->with('status', 'Invitation saved. The member must accept in their account.');
    }

    public function respond(Request $request, int $invitation)
    {
        $data = $request->validate(['decision' => 'required|in:accept,decline']);
        DB::transaction(function () use ($request, $invitation, $data): void {
            $initial = DB::table('business_team_invitations')->where('id', $invitation)
                ->where('invited_user_id', $request->user()->id)->first() ?? abort(404);
            $business = Business::lockForUpdate()->findOrFail($initial->business_id);
            $item = DB::table('business_team_invitations')->where('id', $invitation)->lockForUpdate()->firstOrFail();
            abort_unless($item->invited_user_id === $request->user()->id && $item->status === 'pending', 409);
            abort_unless(now()->lt($item->expires_at) && $business->status === 'active', 409);
            abort_unless($request->user()->hasVerifiedEmail(), 403);
            abort_unless($this->membership($business->id, $request->user()->id)->doesntExist(), 409);
            abort_unless($this->membership($business->id, $item->invited_by_id)->where('role', 'owner')->exists(), 409);
            abort_unless(in_array($item->role, self::STAFF, true), 409);
            if ($data['decision'] === 'accept') {
                DB::table('business_memberships')->insert([
                    'business_id' => $business->id, 'user_id' => $request->user()->id,
                    'role' => $item->role, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('business_team_invitations')->where('id', $invitation)->update([
                'status' => $data['decision'] === 'accept' ? 'accepted' : 'declined',
                'responded_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.team-'.$data['decision'].'ed', $request->user()->id,
                $request->attributes->get('site')->id, [
                    'business' => $business->id, 'invitation' => $invitation, 'role' => $item->role,
                ]);
        }, attempts: 3);

        return redirect('/account/business-invitations')->with('status', 'Invitation response saved.');
    }

    public function revokeInvitation(Request $request, int $business, int $invitation)
    {
        DB::transaction(function () use ($request, $business, $invitation): void {
            $item = $this->visibleBusiness($request, $business, true);
            $this->requireOwner($item->id, $request->user()->id);
            $invite = $this->pending($item->id)->where('id', $invitation)->lockForUpdate()->firstOrFail();
            DB::table('business_team_invitations')->where('id', $invite->id)->update([
                'status' => 'revoked', 'responded_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('business.team-invitation-revoked', $request->user()->id, $request->attributes->get('site')->id,
                ['business' => $business, 'invitation' => $invitation]);
        }, attempts: 3);

        return redirect("/account/businesses/$business/team")->with('status', 'Invitation revoked.');
    }

    public function removeMember(Request $request, int $business, int $member)
    {
        $data = $request->validate(['expected_role' => ['required', Rule::in(self::STAFF)]]);
        DB::transaction(function () use ($request, $business, $member, $data): void {
            $item = $this->visibleBusiness($request, $business, true);
            $this->requireOwner($item->id, $request->user()->id);
            $current = $this->membership($item->id, $member)->lockForUpdate()->first();
            abort_unless($current !== null && $current->role === $data['expected_role'], 409);
            DB::table('business_memberships')->where(['business_id' => $item->id, 'user_id' => $member])->delete();
            DB::table('business_locations')->where('business_id', $item->id)
                ->where('message_contact_user_id', $member)->update(['message_contact_user_id' => null]);
            DB::table('job_postings')->whereIn('business_location_id',
                DB::table('business_locations')->select('id')->where('business_id', $item->id))
                ->where('message_contact_user_id', $member)->update(['message_contact_user_id' => null]);
            Audit::record('business.team-removed', $request->user()->id, $request->attributes->get('site')->id,
                ['business' => $item->id, 'member' => $member, 'role' => $current->role]);
        }, attempts: 3);

        return redirect("/account/businesses/$business/team")->with('status', 'Staff access revoked.');
    }

    private function visibleBusiness(Request $request, int $business, bool $lock = false): Business
    {
        $site = $request->attributes->get('site');

        return Business::whereKey($business)->where('status', 'active')
            ->when($site->location_id !== null, fn ($query) => $query->whereHas('locations',
                fn ($locations) => $locations->where('location_id', $site->location_id)))
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    private function requireOwner(int $business, int $actor): void
    {
        abort_unless($this->membership($business, $actor)->where('role', 'owner')->exists(), 404);
    }

    private function membership(int $business, int $member): Builder
    {
        return DB::table('business_memberships')->where(['business_id' => $business, 'user_id' => $member]);
    }

    private function pending(int $business): Builder
    {
        return DB::table('business_team_invitations')->where(['business_id' => $business, 'status' => 'pending'])
            ->where('expires_at', '>', now());
    }
}
