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
        <div class="panel form-stack">
            <h2>Account and privacy</h2>
            <div><strong>Email address</strong><p>{{ $member->email }} (visible only to you)</p></div>
            <p class="field-note">City preferences, notification controls and public contact options will be added in the upcoming account update.</p>
            <a class="text-link" href="/account/profile">View my public profile</a>
            <a class="text-link" href="/account/community-notifications">Discussion notifications</a>
            <form method="post" action="/account/logout">@csrf<button class="button button-outline">Sign out</button></form>
        </div>
    </div>
</x-layouts.site>
