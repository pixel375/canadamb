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
        <link rel="apple-touch-icon" href="/pwa/icon/180.png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $site->name }}">
        <meta name="theme-color" content="{{ $site->accent['colors'][0] ?? '#2759a5' }}">
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
    </style>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
@php($isPreview = $preview ?? false)
@php($marketEnabled = $networkSettings['modules']['marketplace'] ?? false)
@php($localLabel = $site->location?->name ?? 'Manitoba')
@if($isPreview)<aside class="preview-notice">Preview of {{ $site->name }} <a href="/admin/communities/{{ $site->id }}">Return to community settings</a></aside>@endif
<header class="site-header">
    <div class="container masthead">
        @unless($isPreview)
            <button id="site-menu-open" class="site-menu-trigger" type="button" aria-label="Open all sections menu" aria-controls="site-drawer" aria-haspopup="dialog">
                <x-icon name="menu" size="23"/>
            </button>
        @endunless
        <a class="brand" href="{{ $isPreview ? url()->current() : '/' }}" aria-label="{{ $site->name }} home">
            @if($site->logo_url)
                <img src="{{ $site->logo_url }}" width="42" height="42" alt="">
            @else
                <span class="brand-mark" aria-hidden="true"><i></i></span>
            @endif
            <span><strong>{{ $site->name }}</strong><small>{{ $site->subtitle }}</small></span>
        </a>
        <div class="header-actions">
            @unless($isPreview)
                @if($marketEnabled)
                    <form class="header-search" action="/marketplace" method="get">
                        <x-icon name="search" size="17"/>
                        <input type="search" name="q" maxlength="100" aria-label="Search Marketplace" placeholder="Search Marketplace">
                    </form>
                @endif
                <label class="theme-control" title="Appearance">
                    <x-icon name="moon" size="18"/>
                    <select id="theme-choice" aria-label="Appearance">
                        <option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option>
                    </select>
                </label>
                @auth
                    <a class="sign-in" href="/account/profile" aria-label="My profile"><x-icon name="person" size="18"/> <span>My account</span></a>
                @else
                    <a class="sign-in" href="{{ $site->isNetwork() ? '/login' : '/auth/start' }}" aria-label="Sign in"><x-icon name="person" size="18"/> <span>Sign in</span></a>
                @endauth
                @if($marketEnabled)<a class="button button-primary header-post" href="/marketplace/new"><x-icon name="plus" size="16"/> Post a listing</a>@endif
            @endunless
        </div>
    </div>
    <div class="navigation-wrap">
        <div class="container navigation">
            <nav aria-label="Primary navigation">
                <a @if(request()->is('/')) aria-current="page" @endif href="/">Home</a>
                @if($marketEnabled)<a @if(request()->is('marketplace*')) aria-current="page" @endif href="/marketplace">Marketplace</a>@endif
                @if($networkSettings['modules']['news'] ?? false)<a @if(request()->is('news*')) aria-current="page" @endif href="/news">News</a>@endif
                @if(($networkSettings['modules']['businesses'] ?? false) || ($networkSettings['modules']['jobs'] ?? false))
                    <details class="desktop-nav-group">
                        <summary @if(request()->is('businesses*', 'business-updates', 'jobs*')) aria-current="page" @endif>Businesses <x-icon name="chevron-down" size="15"/></summary>
                        <div class="desktop-nav-submenu">
                            @if($networkSettings['modules']['businesses'] ?? false)<a href="/businesses">Directory</a>@endif
                            @if($networkSettings['modules']['jobs'] ?? false)<a href="/jobs">Jobs</a>@endif
                        </div>
                    </details>
                @endif
                @if($networkSettings['modules']['community'] ?? false)
                    <details class="desktop-nav-group">
                        <summary @if(request()->is('community*', 'events*')) aria-current="page" @endif>Discussions <x-icon name="chevron-down" size="15"/></summary>
                        <div class="desktop-nav-submenu">
                            <a href="/community">All discussions</a>
                            @if($networkSettings['modules']['events'] ?? false)<a href="/events">Events calendar</a>@endif
                        </div>
                    </details>
                @endif
                @if($site->isNetwork())<a href="/communities" @if(request()->is('communities')) aria-current="page" @endif>Communities</a>@endif
            </nav>
            @unless($isPreview)
                @if($site->isNetwork())
                    <a class="scope-link" href="/communities"><x-icon name="pin" size="17"/> {{ $location->name ?? 'All Manitoba' }}</a>
                @else
                    <a class="scope-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main()) }}"><x-icon name="pin" size="17"/> CanadaMB network</a>
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
    <nav class="site-drawer-nav" aria-label="All site sections">
        <a href="/"><x-icon name="home" size="20"/> Home</a>
        <a href="/account/messages"><x-icon name="messages" size="20"/> Messages</a>
        @if($networkSettings['modules']['news'] ?? false)<a href="/news"><x-icon name="news" size="20"/> News</a>@endif
        @if($marketEnabled)<a href="/marketplace"><x-icon name="marketplace" size="20"/> Marketplace</a>@endif
        @if(($networkSettings['modules']['businesses'] ?? false) || ($networkSettings['modules']['jobs'] ?? false))
        <details class="drawer-submenu" @if(request()->is('businesses*', 'jobs*')) open @endif>
            <summary><x-icon name="building" size="20"/> Businesses <x-icon class="drawer-chevron" name="chevron-down" size="17"/></summary>
            <div>
                @if($networkSettings['modules']['businesses'] ?? false)<a href="/businesses">Directory</a>@endif
                @if($networkSettings['modules']['jobs'] ?? false)<a href="/jobs">Jobs</a>@endif
            </div>
        </details>
        @endif
        @if($networkSettings['modules']['community'] ?? false)
        <details class="drawer-submenu" @if(request()->is('community*', 'events*')) open @endif>
            <summary><x-icon name="discussions" size="20"/> Discussions <x-icon class="drawer-chevron" name="chevron-down" size="17"/></summary>
            <div>
                <a href="/community">All discussions</a>
                @if($networkSettings['modules']['events'] ?? false)<a href="/events">Events calendar</a>@endif
            </div>
        </details>
        @endif
        <hr>
        @if($site->isNetwork())
            <a href="/communities"><x-icon name="map" size="20"/> Communities</a>
        @else
            <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/communities') }}"><x-icon name="map" size="20"/> Communities</a>
        @endif
        @auth
            <a href="/account/profile"><x-icon name="person" size="20"/> My Profile</a>
            <a href="/account/settings"><x-icon name="settings" size="20"/> Settings</a>
            @can('manage-network')<a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/admin') }}"><x-icon name="shield" size="20"/> Administration</a>@endcan
        @else
            <a href="{{ $site->isNetwork() ? '/login' : '/auth/start' }}"><x-icon name="person" size="20"/> Sign in</a>
        @endauth
        <hr>
        <a href="/about">About us</a>
        <a href="/contact">Contact us</a>
    </nav>
</dialog>
<nav class="mobile-tab-bar" aria-label="Quick navigation">
    <a href="/" @if(request()->is('/')) aria-current="page" @endif><x-icon name="home" size="22"/><span>Home</span></a>
    <a href="/marketplace" @if(request()->is('marketplace*')) aria-current="page" @endif><x-icon name="marketplace" size="22"/><span>Marketplace</span></a>
    <a href="/account/messages" @if(request()->is('account/messages*')) aria-current="page" @endif><x-icon name="messages" size="22"/><span>Messages</span></a>
    <a href="/account/community-notifications" @if(request()->is('account/community-notifications*')) aria-current="page" @endif><x-icon name="bell" size="22"/><span>Notifications</span></a>
</nav>
@endunless
<main id="main" @if($mainClass ?? null) class="{{ $mainClass }}" @endif>
    @if(session('status'))<div class="container"><p role="status" class="notice">{{ __(session('status')) }}</p></div>@endif
    @if($errors->any())
        <div class="container"><div class="notice notice-error" role="alert"><strong>Check the following details.</strong><ul>
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul></div></div>
    @endif
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
