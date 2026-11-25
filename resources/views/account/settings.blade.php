<x-layouts.site title="My settings">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>My settings</h1><p>Manage your CanadaMB identity across Manitoba communities.</p></div></div>
        <form class="panel form-stack" method="post" action="/account/settings">
            @csrf
            <h2>Public display name</h2>
            <label>Name<input name="name" value="{{ old('name', $member->name) }}" maxlength="80" minlength="2" autocomplete="name" required></label>
            <p class="field-note">Your display name is visible beside discussions, listings and messages.</p>
            <div><button class="button button-primary">Save changes</button></div>
        </form>
        <section class="panel form-stack" aria-labelledby="device-preferences-heading">
            <h2 id="device-preferences-heading">Device preferences</h2>
            <div class="device-community-setting">
                <?php $settingsSite = request()->attributes->get("site"); $savedCommunity = \App\Support\LocationScope::preferred($settingsSite, request()->cookie(\App\Support\LocationScope::PREFERENCE_COOKIE)); ?>
                <strong>Preferred community</strong>
                <p>{{ $settingsSite->isNetwork() ? ($savedCommunity?->name ?? "All Manitoba") : $settingsSite->location?->name }}</p>
                <a class="text-link" href="{{ $settingsSite->isNetwork() ? '/communities' : \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/communities') }}">Manage preferred community <x-icon name="arrow" size="16"/></a>
            </div>
            <label>Appearance
                <select id="theme-choice-settings">
                    <option value="system">Match system</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
            </label>
            <label class="checkbox"><input type="checkbox" id="activity-refresh-setting" checked> Automatically refresh unread counters</label>
            <p class="field-note">These preferences apply to this browser on this website. Open conversations still refresh while you use them.</p>
            <p class="field-note" id="device-preference-status" role="status" aria-live="polite"></p>
        </section>
        <div class="panel form-stack">
            <h2>Account and privacy</h2>
            <div><strong>Email verification</strong><p>{{ $member->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</p>@unless($member->hasVerifiedEmail())<a class="text-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/email/verify') }}">Complete email verification</a>@endunless</div>
            <div><strong>Email address</strong><p>{{ $member->email }} (visible only to you)</p></div>
            <p class="field-note">Your email address is not displayed on your public member profile.</p>
            <a class="text-link" href="/account/profile">View my public profile</a>
            <a class="text-link" href="/account/notifications">Notifications and preferences</a>
            <a class="text-link" href="/account/data-export">Download my data</a>
            @if(\App\Support\PrivacyRequests::enabled())<a class="text-link" href="/account/privacy-requests">Request account deletion or data review</a>@endif
        </div>
        <section class="panel form-stack" aria-labelledby="account-security-heading">
            <h2 id="account-security-heading">Account security</h2>
            @if($member->hasVerifiedEmail())<a class="text-link" href="/account/blocked-members">Manage blocked members</a><a class="text-link" href="/account/listing-reports">My listing reports</a>@if(\App\Support\ContentReports::enabled())<a class="text-link" href="/account/content-reports">My content reports</a>@endif @endif
            <details @if($errors->has('current_password') || $errors->has('password')) open @endif>
                <summary>Change password</summary>
                <form method="post" action="/account/settings/password" class="form-stack mt-3">@csrf
                    <p class="field-note">Changing your password signs you out on every device and community website, including this one.</p>
                    <x-password-field name="current_password" label="Current password" autocomplete="current-password"/>
                    <x-password-field name="password" label="New password" autocomplete="new-password" :minimum="12"/>
                    <small class="field-note">At least 12 characters, including a letter and a number. Maximum 72 bytes.</small>
                    <x-password-field name="password_confirmation" label="Confirm new password" autocomplete="new-password" :minimum="12"/>
                    <button class="button button-primary">Change password and sign out</button>
                </form>
            </details>
            <details><summary>Sign out everywhere</summary><p class="field-note">End your CanadaMB sessions on all devices and community websites.</p><form method="post" action="/account/logout">@csrf<button class="button button-outline">Confirm sign out everywhere</button></form></details>
        </section>
    </div>
</x-layouts.site>
