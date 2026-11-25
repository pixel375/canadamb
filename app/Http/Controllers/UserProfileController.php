<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Identity;
use App\Support\Marketplace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserProfileController extends Controller
{
    public function show(Request $request, User $user, Marketplace $marketplace): View
    {
        $site = $request->attributes->get('site');
        $listings = $marketplace->enabled($site)
            ? $marketplace->visible($site)->where('user_id', $user->id)
                ->latest('published_at')->limit(6)->get()
            : collect();

        return view('account.profile', [
            'member' => $user,
            'isOwn' => $request->user()?->is($user) ?? false,
            'listings' => $listings,
        ]);
    }

    public function mine(Request $request, Marketplace $marketplace): View
    {
        return $this->show($request, $request->user(), $marketplace);
    }

    public function settings(Request $request): View
    {
        return view('account.settings', ['member' => $request->user()]);
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:72',
            'password' => Identity::passwordRules(),
        ]);

        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (strlen($data['current_password']) > 72 || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $user->forceFill(['password' => $data['password']])->save();
            Identity::revoke($user);
            Audit::record('user.password-changed', $user->id);
        });

        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('status', 'Password changed. Sign in again with your new password.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'version' => 'nullable|string|size:64']);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if (isset($data['version']) && ! hash_equals($user->profileVersion(), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Your display name changed. Reload settings before saving.']);
            }
            if ($user->name !== $data['name']) {
                $user->update(['name' => $data['name']]);
                Audit::record('user.profile-updated', $user->id);
            }
        }, attempts: 3);

        return redirect('/account/settings')->with('status', 'Profile settings saved.');
    }
}
