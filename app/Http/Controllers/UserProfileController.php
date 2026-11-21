<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\Marketplace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);
        $user = $request->user();

        if ($user->name !== $data['name']) {
            $user->update(['name' => $data['name']]);
            Audit::record('user.profile-updated', $user->id);
        }

        return redirect('/account/settings')->with('status', 'Profile settings saved.');
    }
}
