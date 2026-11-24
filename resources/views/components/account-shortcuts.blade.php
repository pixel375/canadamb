@props(['heading' => 'Your account'])
<?php
    $site = request()->attributes->get('site');
    $entries = [
        ['path' => '/account/listings', 'icon' => 'marketplace', 'label' => 'My listings', 'note' => 'Manage your Marketplace posts', 'enabled' => $networkSettings['modules']['marketplace'] ?? false],
        ['path' => '/account/saved', 'icon' => 'tag', 'label' => 'Saved listings', 'note' => 'Items you bookmarked', 'enabled' => $networkSettings['modules']['marketplace'] ?? false],
        ['path' => '/account/messages', 'icon' => 'messages', 'label' => 'Messages', 'note' => 'Private conversations', 'enabled' => true, 'count' => $navCounts['messages'] ?? 0],
        ['path' => '/account/notifications', 'icon' => 'bell', 'label' => 'Notifications', 'note' => 'Recent account activity', 'enabled' => true, 'count' => $navCounts['notifications'] ?? 0],
        ['path' => '/account/followed-discussions', 'icon' => 'discussions', 'label' => 'Following discussions', 'note' => 'Conversations you follow', 'enabled' => $networkSettings['modules']['community'] ?? false],
        ['path' => '/account/events', 'icon' => 'calendar', 'label' => 'My events', 'note' => 'Submitted community events', 'enabled' => $networkSettings['modules']['events'] ?? false],
        ['path' => '/account/saved-jobs', 'icon' => 'briefcase', 'label' => 'Saved jobs', 'note' => 'Employment opportunities', 'enabled' => $networkSettings['modules']['jobs'] ?? false],
        ['path' => '/account/businesses', 'icon' => 'building', 'label' => 'My businesses', 'note' => 'Manage business profiles', 'enabled' => $networkSettings['modules']['businesses'] ?? false],
        ['path' => '/account/properties', 'icon' => 'house', 'label' => 'Private property drafts', 'note' => 'Manage unpublished properties', 'enabled' => config('network.property_drafts')],
        ['path' => '/account/settings', 'icon' => 'settings', 'label' => 'Settings', 'note' => 'Account and privacy', 'enabled' => true],
    ];
?>
<section class="account-shortcuts" aria-label="{{ $heading }}">
    <h2>{{ $heading }}</h2>
    <div class="account-shortcut-grid">
        @foreach($entries as $entry)
            @if($entry['enabled'])
                <a class="account-shortcut" href="{{ $entry['path'] }}" @if(request()->is(ltrim($entry['path'], '/'))) aria-current="page" @endif>
                    <span class="account-shortcut-icon"><x-icon :name="$entry['icon']" size="22"/></span>
                    <span class="account-shortcut-copy"><strong>{{ $entry['label'] }}</strong><small>{{ $entry['note'] }}</small></span>
                    @if(($entry['count'] ?? 0) > 0)<x-nav-count :count="$entry['count']" :label="$entry['label']"/>@else<x-icon name="chevron" size="16"/>@endif
                </a>
            @endif
        @endforeach
    </div>
    @can('manage-network')
        <a class="text-link mt-3" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin') }}">Network administration <x-icon name="arrow" size="16"/></a>
    @endcan
    @can('manage-news')
        <a class="text-link mt-3" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin/news') }}">News editorial <x-icon name="arrow" size="16"/></a>
    @endcan
</section>
