<x-layouts.site title="Account settings">
    @php
        $field = static function (string $name, mixed $fallback): mixed {
            $value = old($name, $fallback);

            return is_scalar($value) || $value === null ? $value : $fallback;
        };
    @endphp
    <div class="container page-shell narrow">
        <div class="page-intro">
            <div><h1>Account settings</h1></div>
            <div class="inline-actions">
                <a class="button button-outline" href="/account/profile">My profile</a>
                @if($member->hasVerifiedEmail() && app(\App\Support\Community::class)->enabled(request()->attributes->get('site'), 'community'))
                    <a class="button button-outline" href="/account/discussions">My discussions</a>
                @endif
            </div>
        </div>
        <form class="panel form-stack" method="post" action="/account/settings">
            @csrf
            <h2>Public profile</h2>
            <label>Display name<input name="name" value="{{ $field('name', $member->name) }}" maxlength="80" minlength="2" autocomplete="nickname" required></label>
            <label>Bio<textarea name="bio" rows="4" maxlength="500" aria-describedby="bio-help">{{ $field('bio', $preferences->bio) }}</textarea></label>
            <p class="field-note" id="bio-help">Up to 500 characters. Leave out private contact details.</p>
            <label>Preferred Manitoba community
                <select name="preferred_location_id" aria-describedby="community-help">
                    <option value="">No preference</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}" @selected((string) $field('preferred_location_id', $preferences->preferred_location_id) === (string) $location->id)>{{ $location->name }} · {{ ucfirst($location->type) }}</option>
                    @endforeach
                </select>
            </label>
            <p class="field-note" id="community-help">Listings follow the location where they were posted.</p>
            <fieldset class="member-settings-options">
                <legend>Show on your public profile</legend>
                @foreach(['show_bio' => 'Bio', 'show_city' => 'Preferred community', 'show_listings' => 'Public Marketplace listings'] as $name => $label)
                    <input type="hidden" name="{{ $name }}" value="0">
                    <label class="checkbox"><input type="checkbox" name="{{ $name }}" value="1" @checked($field($name, $preferences->$name))> {{ $label }}</label>
                @endforeach
            </fieldset>

            <fieldset class="member-settings-options" aria-describedby="notification-help">
                <legend>In-app notifications</legend>
                <p class="field-note" id="notification-help">Account and moderation notices are required.</p>
                @foreach([
                    'notify_discussion_replies' => 'Replies to discussions I follow',
                    'notify_marketplace_messages' => 'New Marketplace messages',
                    'notify_business_messages' => 'New Business directory messages',
                    'notify_job_messages' => 'New Jobs messages',
                ] as $name => $label)
                    <input type="hidden" name="{{ $name }}" value="0">
                    <label class="checkbox"><input type="checkbox" name="{{ $name }}" value="1" @checked($field($name, $preferences->$name))> {{ $label }}</label>
                @endforeach
            </fieldset>

            <div class="inline-actions">
                <button class="button button-primary">Save settings</button>
                @if($preferences->updated_at)
                    <span class="field-note">Last updated <time datetime="{{ $preferences->updated_at->toIso8601String() }}">{{ $preferences->updated_at->timezone('America/Winnipeg')->format('M j, Y · g:i a T') }}</time></span>
                @endif
            </div>
        </form>

        <section class="panel form-stack" id="change-password" aria-labelledby="account-security-heading">
            <h2 id="account-security-heading">Account security</h2>
            <div class="member-copy"><strong>Email address</strong><p>{{ $member->email }} · {{ $member->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}</p></div>
            @if($centralAccount)
                <form class="form-stack" method="post" action="/account/settings/password" aria-labelledby="change-password-heading">
                    @csrf
                    <h3 id="change-password-heading">Change password</h3>
                    <p class="field-note">Changing your password signs you out on all devices.</p>
                    <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" aria-describedby="password-help" required></label>
                    <p class="field-note" id="password-help">Use at least 12 characters with letters and numbers.</p>
                    <label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
                    <div><button class="button button-outline">Change password</button></div>
                </form>
            @else
                <a class="text-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/account/settings#change-password') }}">Change password on CanadaMB</a>
            @endif
        </section>
        <form class="mt-4" method="post" action="/account/logout">
            @csrf
            <button class="button button-outline">Sign out</button>
        </form>
    </div>
</x-layouts.site>
