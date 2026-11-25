<x-layouts.site :title="$member->name.' | Member profile'">
    <div class="container page-shell">
        <div class="page-intro member-profile-heading">
            <div class="member-profile-identity"><span class="member-profile-avatar"><x-icon name="person" size="30"/></span><div><p class="section-label">CanadaMB member</p><h1>{{ $member->name }}</h1>
                <p>Member since <time datetime="{{ $member->created_at->toDateString() }}">{{ $member->created_at->format('F Y') }}</time></p>
            </div></div>
            @if($isOwn)<a class="button button-outline" href="/account/settings">Edit my profile</a>@endif
        </div>
        @if($isOwn)
            @unless($member->hasVerifiedEmail())
                <section class="panel account-verification-notice"><x-icon name="shield" size="24"/><div><h2>Verify your email to get started</h2><p>{{ config('network.prelaunch') ? 'During prelaunch, your testing administrator verifies your account.' : 'Complete email verification to post listings, join discussions and send messages.' }}</p><a class="button button-primary" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/email/verify') }}">Check verification</a></div></section>
            @endunless
            <x-account-shortcuts/>
            <details class="panel account-private-details">
                <summary>Private account details</summary>
                <p class="mt-2">Your email address is not shown on your public profile.</p>
                <p class="mt-2">{{ $member->email }}</p>
                <a class="text-link mt-2" href="/account/settings">Manage account settings</a>
            </details>
        @endif
        <section class="homepage-section" aria-labelledby="member-listings-heading">
            <div class="section-heading"><h2 id="member-listings-heading">Public Marketplace listings</h2></div>
            @if($listings->isNotEmpty())
                <div class="listing-grid">
                    @foreach($listings as $item)<x-listing-card :item="$item"/>@endforeach
                </div>
            @else
                <div class="panel"><p>No publicly visible listings from this member in this community.</p></div>
            @endif
        </section>
    </div>
</x-layouts.site>
