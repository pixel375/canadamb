<x-layouts.site :title="$member->name.' | Member profile'">
    <div class="container page-shell">
        <div class="page-intro">
            <div><p class="section-label">CanadaMB member</p><h1>{{ $member->name }}</h1>
                <p>Member since <time datetime="{{ $member->created_at->toDateString() }}">{{ $member->created_at->format('F Y') }}</time></p>
            </div>
            @if($isOwn)<a class="button button-outline" href="/account/settings">Edit my profile</a>@endif
        </div>
        @if($isOwn)
            <section class="panel narrow">
                <h2>Private account details</h2>
                <p>Your email address is not shown on your public profile.</p>
                <p class="mt-2">{{ $member->email }}</p>
                <div class="inline-actions mt-3">
                    <a class="text-link" href="/account/listings">My Marketplace listings</a>
                    <a class="text-link" href="/account/messages">Messages</a>
                    <a class="text-link" href="/account/community-notifications">Notifications</a>
                </div>
            </section>
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
