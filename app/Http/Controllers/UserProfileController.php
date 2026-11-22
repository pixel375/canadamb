<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\MemberSetting;
use App\Models\User;
use App\Support\Audit;
use App\Support\Identity;
use App\Support\Marketplace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserProfileController extends Controller
{
    public function show(Request $request, User $user, Marketplace $marketplace): View
    {
        return $this->profile($request, $user, $marketplace, false);
    }

    public function mine(Request $request, Marketplace $marketplace): View
    {
        return $this->profile($request, $request->user(), $marketplace, true);
    }

    public function settings(Request $request): View
    {
        $member = $request->user();

        return view('account.settings', [
            'member' => $member,
            'preferences' => MemberSetting::find($member->id) ?? new MemberSetting,
            'locations' => Location::selectable()->orderBy('name')->orderBy('id')->get(['id', 'name', 'type']),
            'centralAccount' => $request->attributes->get('site')->isNetwork(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:80',
            'bio' => 'nullable|string|max:500',
            'preferred_location_id' => ['bail', 'nullable', 'integer', Rule::exists(Location::class, 'id')->where('province', 'MB')->where('selectable', true)],
            'show_bio' => 'sometimes|boolean',
            'show_city' => 'sometimes|boolean',
            'show_listings' => 'sometimes|boolean',
            'notify_discussion_replies' => 'sometimes|boolean',
            'notify_marketplace_messages' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($request, $data): void {
            $member = User::lockForUpdate()->findOrFail($request->user()->id);
            $member->name = $data['name'];
            $preferences = MemberSetting::find($member->id) ?? new MemberSetting;
            $preferences->fill(Arr::except($data, 'name'));
            if ($member->isDirty('name') || $preferences->isDirty()) {
                $member->save();
                $preferences->user_id = $member->id;
                $preferences->touch();
                Audit::record('user.profile-updated', $member->id);
            }
        });

        return redirect('/account/settings')->with('status', 'Profile settings saved.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        abort_unless($request->attributes->get('site')->isNetwork(), 404);

        $data = $request->validate([
            'current_password' => 'required|string|max:1024',
            'password' => Identity::passwordRules(),
        ]);

        DB::transaction(function () use ($request, $data): void {
            $member = User::lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($data['current_password'], $member->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')]);
            }

            $member->forceFill(['password' => $data['password']])->save();
            Identity::revoke($member);
            Audit::record('user.password-changed', $member->id);
        });

        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('status', 'Password changed. Sign in with your new password.');
    }

    private function profile(Request $request, User $member, Marketplace $marketplace, bool $isOwn): View
    {
        $site = $request->attributes->get('site');
        $preferences = MemberSetting::find($member->id) ?? new MemberSetting;
        $marketplaceEnabled = $marketplace->enabled($site);
        $showListings = $preferences->show_listings && $marketplaceEnabled;

        return view('account.profile', [
            'member' => $member,
            'isOwn' => $isOwn,
            'initials' => str($member->name)->squish()->explode(' ')->take(2)->map(fn (string $word) => mb_substr($word, 0, 1))->implode(''),
            'bio' => $preferences->show_bio ? $preferences->bio : null,
            'city' => $preferences->show_city && $preferences->preferred_location_id
                ? Location::selectable()->whereKey($preferences->preferred_location_id)->value('name') : null,
            'marketplaceEnabled' => $marketplaceEnabled,
            'showListings' => $showListings,
            'listings' => $showListings
                ? $marketplace->visible($site)->where('user_id', $member->id)->latest('published_at')->latest('id')->limit(6)->get()
                : collect(),
        ]);
    }
}
