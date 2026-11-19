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
                    <a class="sign-in" href="/account/listings" aria-label="My account"><x-icon name="person" size="18"/> <span>My account</span></a>
                @else
                    <a class="sign-in" href="{{ $site->isNetwork() ? '/login' : '/auth/start' }}" aria-label="Sign in"><x-icon name="person" size="18"/> <span>Sign in</span></a>
                @endauth
                @if($marketEnabled)<a class="button button-primary header-post" href="/marketplace/new"><x-icon name="plus" size="16"/> Post a listing</a>@endif
            @endunless
        </div>
    </div>
    <div class="navigation-wrap">
        <div class="container navigation">
            <nav aria-label="Main navigation">
                <a @if(request()->is('/')) aria-current="page" @endif href="/">Home</a>
                @if($networkSettings['modules']['news'] ?? false)<a @if(request()->is('news', 'news/*')) aria-current="page" @endif href="/news">News</a>@endif
                @if($networkSettings['modules']['businesses'] ?? false)<a @if(request()->is('businesses*', 'business-updates')) aria-current="page" @endif href="/businesses">Businesses</a>@endif
                @if($marketEnabled)<a @if(request()->is('marketplace', 'marketplace/*')) aria-current="page" @endif href="/marketplace">Marketplace</a>@endif
                @if($site->isNetwork())<a @if(request()->is('communities')) aria-current="page" @endif href="/communities">Communities</a>@endif
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
        <nav aria-label="Footer">
            @if($site->isNetwork())<a href="/communities">Manitoba communities</a>
            @else<a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main()) }}">Part of CanadaMB</a>@endif
            @if($site->contact_email)<a href="mailto:{{ $site->contact_email }}">Contact</a>@endif
            <a href="/photo-credits">Photo credits</a>
        </nav>
    </div>
</footer>
</body>
</html>
