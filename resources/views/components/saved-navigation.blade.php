@props(['current', 'total'])
<?php $savedSite = request()->attributes->get('site'); ?>
<nav class="inline-actions saved-navigation" aria-label="Saved content">
    @if(app(\App\Support\Marketplace::class)->enabled($savedSite))<a class="button button-outline" href="/account/saved" @if($current === 'listings') aria-current="page" @endif>Listings @if($current === 'listings')({{ $total }})@endif</a>@endif
    @if(app(\App\Support\JobBoard::class)->enabled($savedSite))<a class="button button-outline" href="/account/saved-jobs" @if($current === 'jobs') aria-current="page" @endif>Jobs @if($current === 'jobs')({{ $total }})@endif</a>@endif
    @if(\App\Support\MarketplaceSearches::enabled($savedSite))<a class="button button-outline" href="/account/marketplace-searches" @if($current === 'marketplace-searches') aria-current="page" @endif>Marketplace searches</a>@endif
    @if(\App\Support\JobSearches::enabled($savedSite))<a class="button button-outline" href="/account/job-searches" @if($current === 'job-searches') aria-current="page" @endif>Job searches and alerts</a>@endif
    @if(app(\App\Support\Community::class)->enabled($savedSite, 'community'))<a class="button button-outline" href="/account/followed-discussions">Followed discussions</a>@endif
</nav>
