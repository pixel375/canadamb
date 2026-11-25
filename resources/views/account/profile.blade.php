<x-layouts.site :title="$member->name.' | Member profile'">
    <div class="container page-shell">
        <div class="page-intro member-profile-heading">
            <div class="member-profile-identity"><span class="member-profile-avatar"><x-icon name="person" size="30"/></span><div><p class="section-label">CanadaMB member</p><h1>{{ $member->name }}</h1>
                <p>Member since <time datetime="{{ $member->created_at->toDateString() }}">{{ $member->created_at->format('F Y') }}</time></p>
            </div></div>
            @if($isOwn)<a class="button button-outline" href="/account/settings">Edit my profile</a>@else<x-content-report-link type="profile" :target="$member->id"/>@endif
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
        @if($marketEnabled)
        <section class="homepage-section" id="member-listings" aria-labelledby="member-listings-heading">
            <div class="section-heading"><h2 id="member-listings-heading">Public Marketplace listings</h2><span class="field-note">{{ $listingCount }} active {{ \Illuminate\Support\Str::plural('listing', $listingCount) }}</span></div>
            @if($listingCount || array_filter($filters))
                <form method="get" action="{{ $isOwn ? '/account/profile' : '/users/'.$member->id }}#member-listings" class="panel filter-bar mb-3">
                    <label>Search listings<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label>
                    <label>Category<select name="category"><option value="">All categories</option>@foreach($categories as $slug => $label)<option value="{{ $slug }}" @selected(($filters['category'] ?? '') === $slug)>{{ $label }}</option>@endforeach</select></label>
                    <label>Sort<select name="sort"><option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>Newest</option><option value="price-low" @selected(($filters['sort'] ?? '') === 'price-low')>Price: low to high</option><option value="price-high" @selected(($filters['sort'] ?? '') === 'price-high')>Price: high to low</option></select></label>
                    <div class="inline-actions"><button class="button button-primary">Filter listings</button><a class="text-link" href="{{ $isOwn ? '/account/profile' : '/users/'.$member->id }}#member-listings">Reset</a></div>
                </form>
                <p class="field-note">{{ $listings->total() }} matching listings</p>
            @endif
            @if($listings->isNotEmpty())
                <div class="listing-grid">
                    @foreach($listings as $item)<x-listing-card :item="$item"/>@endforeach
                </div>
            @else
                <div class="panel"><p>{{ $listingCount ? 'No public listings match these filters.' : 'No publicly visible listings from this member in this community.' }}</p></div>
            @endif
            {{ $listings->fragment('member-listings')->links() }}
        </section>
        @endif
    </div>
</x-layouts.site>
