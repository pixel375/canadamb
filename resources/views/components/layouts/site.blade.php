@props(['title' => null, 'description' => null, 'canonical' => null, 'preview' => false, 'location' => null, 'mainClass' => null, 'structuredData' => null])
<!doctype html>
<html lang="en-CA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $site->seo_title ?? $site->name.' | '.$site->subtitle }}</title>
    <meta name="description" content="{{ $description ?? $site->description }}">
    @if($preview ?? false)
        <meta name="robots" content="noindex, nofollow">
    @elseif(isset($canonical) || request()->is('/'))
        <link rel="canonical" href="{{ $canonical ?? \App\Support\NetworkUrl::to($site) }}">
    @endif
    <meta property="og:title" content="{{ $title ?? $site->name }}">
    <meta property="og:description" content="{{ $description ?? $site->description }}">
    @if($structuredData && ! $preview)
        <script type="application/ld+json">{!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @endif
    @if(! ($preview ?? false) && ($networkSettings['pwa_install_enabled'] ?? true))
        <link rel="manifest" href="/app.webmanifest">
        <link rel="apple-touch-icon" href="/pwa/icon/180.png?v=2">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $site->name }}">
        <meta name="theme-color"
              content="{{ $networkSettings['palette']['dark']['header'] ?? '#121C2A' }}"
              data-light-color="{{ $networkSettings['palette']['light']['header'] ?? '#FFFFFF' }}"
              data-dark-color="{{ $networkSettings['palette']['dark']['header'] ?? '#121C2A' }}">
    @endif
    <meta name="color-scheme" content="light dark">
    @unless(app()->runningUnitTests())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
    <style>
        :root {
            @foreach($networkSettings['palette'] as $mode => $tokens)
                @foreach($tokens as $key => $value)
                    --{{ $mode }}-{{ str_replace('_', '-', $key) }}: {{ $value }};
                @endforeach
            @endforeach
            --site-accent: {!! $site->accentCss() !!};
            --accent-size: {{ $site->accent['thickness'] }}px;
            --accent-radius: {{ $site->accent['radius'] ?? 0 }}px;
            --accent-shadow: {{ ($site->accent['shadow'] ?? false) ? '0 1px 4px rgb(0 0 0 / 0.18)' : 'none' }};
        }
        .search-results-context { padding-top: 16px; }
        .search-results-navigation { display: grid; gap: 8px; }
        .search-results-query { overflow-wrap: anywhere; }
        .search-results-navigation .inline-actions { gap: 8px; }
    </style>
</head>
<body @if(!$preview && auth()->user()?->hasVerifiedEmail()) data-activity-counts="/account/activity-counts" @endif>
<a class="skip-link" href="#main">Skip to content</a>
<?php $isPreview = $preview ?? false; ?>
<?php $marketEnabled = $networkSettings['modules']['marketplace'] ?? false; ?>
<?php $localLabel = $site->location?->name ?? 'Manitoba'; ?>
<?php
    $browsePaths = ['/', 'marketplace', 'real-estate', 'businesses', 'business-updates', 'community', 'jobs', 'events', 'news'];
    $browsePlace = $location ?? (in_array(request()->path(), $browsePaths, true)
        ? \App\Support\LocationScope::forBrowsing($site, request()->query('location'), request()->is('jobs'))
        : ($site->location ?? \App\Support\LocationScope::preferred($site, request()->cookie(\App\Support\LocationScope::PREFERENCE_COOKIE))));
    $browseLink = fn (string $path) => \App\Support\LocationScope::browseUrl($site, $path, $browsePlace);
?>
<?php $registerLink = !config('network.prelaunch') || ($networkSettings['prelaunch_test_registration_enabled'] ?? false) === true ? ($site->isNetwork() ? '/register' : \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/register?community='.$site->id)) : null; ?>
@if($isPreview)<aside class="preview-notice">Preview of {{ $site->name }} <a href="/admin/communities/{{ $site->id }}">Return to community settings</a></aside>@endif
<header class="site-header">
    <div class="container masthead">
        @unless($isPreview)
            <button id="site-menu-open" class="site-menu-trigger" type="button" aria-label="Open all sections menu" aria-controls="site-drawer" aria-haspopup="dialog">
                <x-icon name="menu" size="23"/>
            </button>
        @endunless
        <a class="brand" href="{{ $isPreview ? url()->current() : $browseLink('/') }}" aria-label="{{ $site->name }} home">
            @if($site->logo_url)
                <img src="{{ $site->logo_url }}" width="42" height="42" alt="">
            @else
                <span class="brand-mark" aria-hidden="true"><i></i></span>
            @endif
            <span><strong>{{ $site->name }}</strong><small>{{ $site->subtitle }}</small></span>
        </a>
        @if(!$isPreview && $marketEnabled)
            <form class="header-search" action="/marketplace" method="get" role="search" aria-label="Marketplace quick search">
                <x-icon name="search" size="19"/>
                <input type="search" name="q" maxlength="100" aria-label="Search Marketplace" placeholder="Search Marketplace">
                @if($site->isNetwork() && $browsePlace)<input type="hidden" name="location" value="{{ $browsePlace->slug }}">@endif
                <button class="header-search-submit" type="submit" aria-label="Submit Marketplace search"><x-icon name="arrow" size="18"/></button>
            </form>
        @endif
        <div class="header-actions">
            @unless($isPreview)
                <label class="theme-control" title="Appearance">
                    <x-icon name="moon" size="18"/>
                    <select id="theme-choice" aria-label="Appearance">
                        <option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option>
                    </select>
                </label>
                @auth
                    <a class="sign-in header-inbox" href="/account/messages" title="Messages" @if(request()->is('account/messages*')) aria-current="page" @endif><x-icon name="messages" size="18"/> <span class="header-action-label">Messages</span><x-nav-count :count="$navCounts['messages']" label="messages"/></a>
                    <a class="sign-in header-inbox" href="/account/notifications" title="Notifications" @if(request()->is('account/notifications*', 'account/community-notifications*')) aria-current="page" @endif><x-icon name="bell" size="18"/> <span class="header-action-label">Notifications</span><x-nav-count :count="$navCounts['notifications']" label="notifications"/></a>
                    <details class="header-account-menu">
                        <summary aria-label="Account menu">
                            <span class="header-account-avatar" aria-hidden="true"><x-icon name="person" size="19"/></span>
                            <span class="header-account-title">Account</span>
                            <x-icon class="header-account-chevron" name="chevron-down" size="15"/>
                        </summary>
                        <div class="header-account-dropdown" role="group" aria-label="Account actions">
                            <div class="header-account-intro">
                                <strong>{{ auth()->user()->name }}</strong>
                                <small>Your CanadaMB account</small>
                            </div>
                            <a href="/account/profile" aria-label="My profile"><x-icon name="person" size="17"/> My profile</a>
                            <a href="/account/settings"><x-icon name="settings" size="17"/> Settings</a>
                            @if($marketEnabled)
                                <a href="/account/listings"><x-icon name="marketplace" size="17"/> My listings</a>
                                <a href="/account/saved"><x-icon name="tag" size="17"/> Saved listings</a>
                            @endif
                            @can('manage-network')
                                <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin') }}"><x-icon name="shield" size="17"/> Administration</a>
                            @endcan
                            <form method="post" action="/account/logout">
                                @csrf
                                <button type="submit"><x-icon name="close" size="17"/> Sign out</button>
                            </form>
                        </div>
                    </details>
                    <a class="sign-in header-profile-mobile" href="/account/profile" aria-label="My profile"><x-icon name="person" size="19"/></a>
                @else
                    <a class="sign-in" href="{{ $site->isNetwork() ? '/login' : '/auth/start' }}" aria-label="Sign in"><x-icon name="person" size="18"/> <span>Sign in</span></a>
                    @if($registerLink)<a class="text-link header-register" href="{{ $registerLink }}">Create account</a>@endif
                @endauth
                @if($marketEnabled)<a class="button button-primary header-post" href="{{ $browseLink('/marketplace/new') }}" title="Post a listing"><x-icon name="plus" size="16"/> <span>Post a listing</span></a>@endif
            @endunless
        </div>
    </div>
    <div class="navigation-wrap">
        <div class="container navigation">
            <nav aria-label="Primary navigation">
                <a @if(request()->is('/')) aria-current="page" @endif href="{{ $browseLink('/') }}"><x-icon name="home" size="17"/> <span>Home</span></a>
                @if($marketEnabled)
                    <a @if(request()->is('marketplace*')) aria-current="page" @endif href="{{ $browseLink('/marketplace') }}"><x-icon name="marketplace" size="17"/> <span>Marketplace</span></a>
                    <a class="desktop-real-estate" @if(request()->is('real-estate*')) aria-current="page" @endif href="{{ $browseLink('/real-estate') }}"><x-icon name="house" size="17"/> <span>Real Estate</span></a>
                @endif
                @if($networkSettings['modules']['news'] ?? false)<a @if(request()->is('news*')) aria-current="page" @endif href="{{ $browseLink('/news') }}"><x-icon name="news" size="17"/> <span>News</span></a>@endif
                @if(($networkSettings['modules']['businesses'] ?? false) || ($networkSettings['modules']['jobs'] ?? false))
                    <details class="desktop-nav-group">
                        <summary @if(request()->is('businesses*', 'business-updates', 'jobs*')) aria-current="page" @endif><x-icon name="building" size="17"/> <span>Businesses</span> <x-icon class="nav-group-chevron" name="chevron-down" size="15"/></summary>
                        <div class="desktop-nav-submenu">
                            @if($networkSettings['modules']['businesses'] ?? false)
                                <a href="{{ $browseLink('/businesses') }}"><x-icon name="building" size="18"/> Directory</a>
                                <a href="{{ $browseLink('/business-updates') }}"><x-icon name="news" size="18"/> Business updates</a>
                            @endif
                            @if($networkSettings['modules']['jobs'] ?? false)<a href="{{ $browseLink('/jobs') }}"><x-icon name="briefcase" size="18"/> Jobs</a>@endif
                        </div>
                    </details>
                @endif
                @if($networkSettings['modules']['community'] ?? false)
                    <details class="desktop-nav-group">
                        <summary @if(request()->is('community*', 'events*')) aria-current="page" @endif><x-icon name="discussions" size="17"/> <span>Discussions</span> <x-icon class="nav-group-chevron" name="chevron-down" size="15"/></summary>
                        <div class="desktop-nav-submenu">
                            <a href="{{ $browseLink('/community') }}"><x-icon name="discussions" size="18"/> All discussions</a>
                            @if($networkSettings['modules']['events'] ?? false)<a href="{{ $browseLink('/events') }}"><x-icon name="calendar" size="18"/> Events calendar</a>@endif
                        </div>
                    </details>
                @endif
                @if($site->isNetwork())<a href="/communities" @if(request()->is('communities')) aria-current="page" @endif><x-icon name="map" size="17"/> <span>Communities</span></a>@endif
            </nav>
            @unless($isPreview)
                @if($site->isNetwork())
                    <a class="scope-link" href="/communities"><x-icon name="pin" size="17"/> <span>{{ $browsePlace?->name ?? 'All Manitoba' }}</span></a>
                @else
                    <a class="scope-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main()) }}"><x-icon name="pin" size="17"/> <span>CanadaMB network</span></a>
                @endif
            @endunless
        </div>
    </div>
    <div class="site-accent" aria-hidden="true"></div>
</header>
@unless($isPreview)
<dialog id="site-drawer" class="site-drawer" aria-label="All sections">
    <div class="site-drawer-head">
        <strong>Explore {{ $site->name }}</strong>
        <button id="site-menu-close" class="site-drawer-close" type="button" aria-label="Close menu"><x-icon name="close" size="22"/></button>
    </div>
    @if($marketEnabled)
        <div class="site-drawer-quick">
            <form class="site-drawer-search" action="/marketplace" method="get" role="search">
                <x-icon name="search" size="19"/>
                <label class="sr-only" for="site-drawer-query">Search Marketplace</label>
                <input id="site-drawer-query" type="search" name="q" placeholder="Search local listings" maxlength="100">
                @if($site->isNetwork() && $browsePlace)<input type="hidden" name="location" value="{{ $browsePlace->slug }}">@endif
                <button type="submit" aria-label="Submit Marketplace search"><x-icon name="arrow" size="18"/></button>
            </form>
            <a class="button button-primary site-drawer-post" href="{{ $browseLink('/marketplace/new') }}"><x-icon name="plus" size="18"/> Post a listing</a>
        </div>
    @endif
    <nav class="site-drawer-nav" aria-label="All site sections">
        <a href="{{ $browseLink('/') }}" aria-current="{{ request()->is('/') ? 'page' : 'false' }}"><x-icon name="home" size="20"/> Home</a>
        <a href="/account/messages" aria-current="{{ request()->is('account/messages*') ? 'page' : 'false' }}"><x-icon name="messages" size="20"/> Messages <x-nav-count :count="$navCounts['messages']" label="messages"/></a>
        <a href="/account/notifications" aria-current="{{ request()->is('account/notifications*', 'account/community-notifications*') ? 'page' : 'false' }}"><x-icon name="bell" size="20"/> Notifications <x-nav-count :count="$navCounts['notifications']" label="notifications"/></a>
        @if($networkSettings['modules']['news'] ?? false)<a href="{{ $browseLink('/news') }}"><x-icon name="news" size="20"/> News</a>@endif
        @if($marketEnabled)
            <a href="{{ $browseLink('/marketplace') }}" aria-current="{{ request()->is('marketplace*') ? 'page' : 'false' }}"><x-icon name="marketplace" size="20"/> Marketplace</a>
            <a href="{{ $browseLink('/real-estate') }}" aria-current="{{ request()->is('real-estate*') ? 'page' : 'false' }}"><x-icon name="house" size="20"/> Real Estate</a>
        @endif
        @if(($networkSettings['modules']['businesses'] ?? false) || ($networkSettings['modules']['jobs'] ?? false))
        <details class="drawer-submenu" @if(request()->is('businesses*', 'jobs*')) open @endif>
            <summary><x-icon name="building" size="20"/> Businesses <x-icon class="drawer-chevron" name="chevron-down" size="17"/></summary>
            <div>
                @if($networkSettings['modules']['businesses'] ?? false)<a href="{{ $browseLink('/businesses') }}">Directory</a>@endif
                @if($networkSettings['modules']['jobs'] ?? false)<a href="{{ $browseLink('/jobs') }}">Jobs</a>@endif
            </div>
        </details>
        @endif
        @if($networkSettings['modules']['community'] ?? false)
        <details class="drawer-submenu" @if(request()->is('community*', 'events*')) open @endif>
            <summary><x-icon name="discussions" size="20"/> Discussions <x-icon class="drawer-chevron" name="chevron-down" size="17"/></summary>
            <div>
                <a href="{{ $browseLink('/community') }}">All discussions</a>
                @if($networkSettings['modules']['events'] ?? false)<a href="{{ $browseLink('/events') }}">Events calendar</a>@endif
            </div>
        </details>
        @endif
        <hr>
        @if($site->isNetwork())
            <a href="/communities"><x-icon name="map" size="20"/> Communities <small>{{ $browsePlace?->name ?? 'All Manitoba' }}</small></a>
        @else
            <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/communities') }}"><x-icon name="map" size="20"/> Communities</a>
        @endif
        @auth
            <a href="/account/profile"><x-icon name="person" size="20"/> My Profile</a>
            <a href="/account/settings"><x-icon name="settings" size="20"/> Settings</a>
            @can('manage-network')<a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin') }}"><x-icon name="shield" size="20"/> Administration</a>@endcan
        @else
            <a href="{{ $site->isNetwork() ? '/login' : '/auth/start' }}"><x-icon name="person" size="20"/> Sign in</a>
            @if($registerLink)<a href="{{ $registerLink }}"><x-icon name="plus" size="20"/> Create account</a>@endif
        @endauth
        <hr>
        <a href="/about">About us</a>
        <a href="/contact">Contact us</a>
        <label class="drawer-theme-control">
            <x-icon name="moon" size="19"/> Appearance
            <select id="theme-choice-mobile" aria-label="Mobile appearance">
                <option value="system">System</option>
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
        </label>
    </nav>
</dialog>
<nav class="mobile-tab-bar" aria-label="Quick navigation">
    <a href="{{ $browseLink('/') }}" @if(request()->is('/')) aria-current="page" @endif><x-icon name="home" size="22"/><span>Home</span></a>
    <a href="{{ $browseLink('/marketplace') }}" @if(request()->is('marketplace*', 'real-estate*')) aria-current="page" @endif><x-icon name="marketplace" size="22"/><span>Marketplace</span></a>
    <a href="/account/messages" @if(request()->is('account/messages*')) aria-current="page" @endif><x-icon name="messages" size="22"/><span>Messages</span><x-nav-count :count="$navCounts['messages']" label="messages"/></a>
    <a href="/account/notifications" @if(request()->is('account/notifications*', 'account/community-notifications*')) aria-current="page" @endif><x-icon name="bell" size="22"/><span>Notifications</span><x-nav-count :count="$navCounts['notifications']" label="notifications"/></a>
</nav>
@endunless
<main id="main" @if($mainClass ?? null) class="{{ $mainClass }}" @endif>
    @if(session('status'))<div class="container"><p role="status" class="notice">{{ __(session('status')) }}</p></div>@endif
    @if($errors->any())
        <div class="container"><div class="notice notice-error" role="alert"><strong>Check the following details.</strong><ul>
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul></div></div>
    @endif
    @unless($isPreview)
        <?php
            $searchSections = array_filter([
                'marketplace' => $marketEnabled ? 'Marketplace' : null,
                'businesses' => app(\App\Support\BusinessDirectory::class)->enabled($site) ? 'Businesses' : null,
                'community' => ($networkSettings['modules']['community'] ?? false) ? 'Discussions' : null,
                'jobs' => ($networkSettings['modules']['jobs'] ?? false) ? 'Jobs' : null,
                'real-estate' => $marketEnabled && config('network.real_estate_public_listings') ? 'Real Estate' : null,
                'news' => ($networkSettings['modules']['news'] ?? false) ? 'News' : null,
                'events' => ($networkSettings['modules']['events'] ?? false) ? 'Events' : null,
            ]);
            $searchSection = request()->path();
            $searchTerm = request()->query('q');
        ?>
        @if(isset($searchSections[$searchSection]) && is_string($searchTerm) && trim($searchTerm) !== '' && count($searchSections) > 1)
            <?php
                $searchLocation = request()->query('location');
                $searchPlace = null;
                if ($site->isNetwork() && is_string($searchLocation) && trim($searchLocation) !== '') {
                    $searchPlace = \App\Models\Location::selectable()
                        ->where($searchSection === 'jobs' ? 'name' : 'slug', $searchLocation)->first();
                }
                $searchScopeUnknown = $site->isNetwork() && is_string($searchLocation) && trim($searchLocation) !== '' && ! $searchPlace;
            ?>
            <div class="container search-results-context">
                <nav class="panel search-results-navigation" aria-label="Search other sections">
                    <p class="section-label">Search across sections</p>
                    <p class="search-results-query">Results for <strong>{{ $searchTerm }}</strong> · {{ $searchPlace?->name ?? $site->location?->name ?? 'Manitoba' }}</p>
                    <div class="inline-actions">
                        @foreach($searchSections as $section => $label)
                            @if($section === $searchSection)
                                <span class="button button-primary" aria-current="page">{{ $label }}</span>
                            @else
                                <?php
                                    $searchParams = ['q' => $searchTerm];
                                    if ($searchPlace) {
                                        $searchParams['location'] = $section === 'jobs' ? $searchPlace->name : $searchPlace->slug;
                                    }
                                ?>
                                <a class="button button-outline" href="{{ '/'.$section.'?'.http_build_query($searchParams, '', '&', PHP_QUERY_RFC3986) }}">{{ $label }}</a>
                            @endif
                        @endforeach
                    </div>
                    @if($searchScopeUnknown)
                        <p class="field-note">Your location filter could not be matched to a community. Other sections will search all Manitoba.</p>
                    @endif
                </nav>
            </div>
        @endif
    @endunless
    {{ $slot }}
</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <div>
            <strong>{{ $site->name }}</strong>
            <p>Independent Manitoba community network.</p>
        </div>
        @if(! ($preview ?? false) && ($networkSettings['pwa_install_enabled'] ?? true))
            <div class="pwa-install" id="pwa-install" hidden>
                <button class="button button-outline" type="button" id="pwa-install-button"
                    aria-controls="pwa-install-help">Install App</button>
                <p id="pwa-install-help" class="pwa-install-help" hidden>
                    In Safari, tap Share, then Add to Home Screen. Enable "Open as Web App" if offered.
                </p>
            </div>
        @endif
        <nav aria-label="Footer">
            @if($site->isNetwork())<a href="/communities">Manitoba communities</a>
            @else<a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main()) }}">Part of CanadaMB</a>@endif
            @if($site->contact_email)<a href="mailto:{{ $site->contact_email }}">Contact</a>@endif
            <a href="/about">About us</a>
            <a href="/contact">Contact us</a>
            <a href="/photo-credits">Photo credits</a>
        </nav>
    </div>
</footer>
</body>
</html>
