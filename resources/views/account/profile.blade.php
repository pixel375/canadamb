<x-layouts.site :title="$member->name.' | Member profile'">
    <div class="container page-shell">
        <div class="page-intro">
            <div class="member-heading">
                <span class="member-avatar" aria-hidden="true">{{ $initials }}</span>
                <div class="member-details">
                    <h1>{{ $member->name }}</h1>
                    <p>Member since <time datetime="{{ $member->created_at->format('Y-m') }}">{{ $member->created_at->format('F Y') }}</time></p>
                    @if($city)<p>Based in {{ $city }}</p>@endif
                </div>
            </div>
            @if($isOwn)
                <div class="inline-actions">
                    <a class="button button-outline" href="/users/{{ $member->id }}">View public profile</a>
                    <a class="button button-primary" href="/account/settings">Edit profile</a>
                </div>
            @endif
        </div>
        @if(filled($bio))
            <section class="panel member-copy" aria-labelledby="member-about-heading">
                <h2 id="member-about-heading">About</h2>
                <p class="prose-text">{{ $bio }}</p>
            </section>
        @endif
        @if($isOwn)
            <section class="panel" aria-labelledby="member-activity-heading">
                <h2 id="member-activity-heading">My activity</h2>
                <p class="member-copy field-note">Private email address · {{ $member->email }}</p>
                <div class="inline-actions">
                    <a class="text-link" href="/account/notifications">Notifications</a>
                    @if($marketplaceEnabled)
                        <a class="text-link" href="/account/listings">My Marketplace listings</a>
                        <a class="text-link" href="/account/messages">Messages</a>
                    @endif
                </div>
            </section>
        @endif
        @if($showListings)
            <section class="homepage-section" aria-labelledby="member-listings-heading">
                <div class="section-heading"><h2 id="member-listings-heading">Public Marketplace listings</h2></div>
                @if($listings->isNotEmpty())
                    <div class="listing-grid">
                        @foreach($listings as $item)<x-listing-card :item="$item"/>@endforeach
                    </div>
                @else
                    <div class="panel"><p>No public listings in this community.</p></div>
                @endif
            </section>
        @endif
    </div>
</x-layouts.site>
