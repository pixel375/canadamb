@props(['title'])
<x-layouts.site :title="$title.' | Network administration'">
    <div class="container page-shell">
        <div class="admin-shell">
            <nav class="admin-nav panel" aria-label="Network administration">
                <details class="admin-nav-disclosure" open>
                    <summary>Administration sections <x-icon name="chevron-down" size="18"/></summary>
                    <div class="admin-nav-links">
                        <strong>Network administration</strong>
                @can('manage-network')
                <a href="/admin" @if(request()->is('admin')) aria-current="page" @endif>Network overview</a>
                <a href="/admin/communities" @if(request()->is('admin/communities*')) aria-current="page" @endif>Community websites</a>
                @can('manage-theme')
                    <a href="/admin/commercial" @if(request()->is('admin/commercial')) aria-current="page" @endif>Advertising &amp; subscriptions</a>
                    @if(\App\Support\PrivacyRequests::enabled())<a href="/admin/privacy-requests" @if(request()->is('admin/privacy-requests*')) aria-current="page" @endif>Privacy requests</a>@endif
                    <a href="/admin/settings" @if(request()->is('admin/settings*')) aria-current="page" @endif>Global settings</a>
                    <a href="/admin/theme" @if(request()->is('admin/theme*')) aria-current="page" @endif>Theme & appearance</a>
                    @if(config('network.prelaunch'))<a href="/admin/test-accounts" @if(request()->is('admin/test-accounts*')) aria-current="page" @endif>Prelaunch test accounts</a>@endif
                @endcan
                <strong class="admin-nav-group-label">Content review</strong>
                <a href="/admin/moderation" @if(request()->is('admin/moderation*')) aria-current="page" @endif>Marketplace review</a>
                <a href="/admin/community" @if(request()->is('admin/community')) aria-current="page" @endif>Discussions &amp; events</a>
                @if(\App\Support\ContentReports::enabled())<a href="/admin/content-reports" @if(request()->is('admin/content-reports*')) aria-current="page" @endif>Content reports</a>@endif
                <a href="/admin/jobs" @if(request()->is('admin/jobs')) aria-current="page" @endif>Job moderation</a>
                @if(config('network.property_drafts'))<a href="/admin/properties" @if(request()->is('admin/properties*')) aria-current="page" @endif>Private property review</a>@endif
                <a href="/admin/business-review" @if(request()->is('admin/business-review')) aria-current="page" @endif>Business review</a>
                <strong class="admin-nav-group-label">Operations</strong>
                <a href="/admin/readiness" @if(request()->is('admin/readiness')) aria-current="page" @endif>Launch readiness</a>
                <a href="/admin/audit" @if(request()->is('admin/audit')) aria-current="page" @endif>Audit log</a>
                @endcan
                @can('manage-news')<a href="/admin/news" @if(request()->is('admin/news*')) aria-current="page" @endif>News editorial</a>@endcan
                    </div>
                </details>
            </nav>
            <section aria-label="{{ $title }}">{{ $slot }}</section>
        </div>
    </div>
</x-layouts.site>
