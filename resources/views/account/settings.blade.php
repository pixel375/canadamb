<x-layouts.site title="My settings">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>My settings</h1><p>Manage your CanadaMB identity across Manitoba communities.</p></div></div>
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
            <div><strong>Email address</strong><p>{{ $member->email }} (visible only to you)</p></div>
            <p class="field-note">Your email address is not displayed on your public member profile.</p>
            <a class="text-link" href="/account/profile">View my public profile</a>
            <a class="text-link" href="/account/notifications">View notifications</a>
            <form method="post" action="/account/logout">@csrf<button class="button button-outline">Sign out everywhere</button></form>
        </div>
    </div>
</x-layouts.site>
