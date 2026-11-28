<?php

namespace App\Http\Controllers;

use App\Models\ListingCategory;
use App\Models\User;
use App\Support\Audit;
use App\Support\Identity;
use App\Support\KeywordSearch;
use App\Support\Marketplace;
use App\Support\SignupLocations;
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
        $marketEnabled = $marketplace->enabled($site);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:64|exists:listing_categories,slug',
            'sort' => 'nullable|in:recent,price-low,price-high']);
        $listings = null;
        $categories = collect();
        $listingCount = 0;
        if ($marketEnabled) {
            $inventory = $marketplace->visible($site)->where('user_id', $user->id);
            $listingCount = (clone $inventory)->count();
            $categoryIds = (clone $inventory)->reorder()->select('category')->distinct()->pluck('category');
            $categories = ListingCategory::whereIn('slug', $categoryIds)->orderBy('name')->pluck('name', 'slug');
            $matching = $inventory->when(filled($filters['q'] ?? null), fn ($query) => KeywordSearch::apply($query, $filters['q'], ['title', 'description']))
                ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
            match ($filters['sort'] ?? 'recent') {
                'price-low' => $matching->orderBy('price_cents'),
                'price-high' => $matching->orderByDesc('price_cents'),
                default => null,
            };
            $listings = $matching->latest('published_at')->latest('id')->paginate(12, pageName: 'listing_page')->appends($filters);
        }

        return view('account.profile', [
            'member' => $user,
            'signupLocation' => SignupLocations::publicName($user->id),
            'isOwn' => $request->user()?->is($user) ?? false,
            'listings' => $listings, 'categories' => $categories, 'filters' => $filters, 'listingCount' => $listingCount, 'marketEnabled' => $marketEnabled,
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
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if (! hash_equals($user->profileVersion(), $data['version'])) {
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
