@props(['title'])
<x-layouts.site :title="$title.' | Network administration'">
    <?php
        $groups = \App\Support\AdminNavigation::groups(auth()->user());
        $active = \App\Support\AdminNavigation::active($groups, request()->path());
    ?>
    <div class="container page-shell admin-workspace">
        <div class="admin-workspace-head"><div><span class="section-label">CanadaMB control centre</span><strong>Administration</strong></div><span class="admin-workspace-mode"><x-icon name="shield" size="16"/> {{ config('network.prelaunch') ? 'Private prelaunch' : 'Network operations' }}</span><a class="text-link" href="/">View website <x-icon name="arrow" size="15"/></a></div>
        <div class="admin-shell">
            <nav class="admin-nav panel" aria-label="Network administration">
                <details class="admin-nav-disclosure" open>
                    <summary>Administration sections <x-icon name="chevron-down" size="18"/></summary>
                    <div class="admin-nav-links">
                        @foreach($groups as $group => $links)
                            <div class="admin-nav-group">
                                <h2>{{ $group }}</h2>
                                <div class="admin-nav-group-items">
                                    @foreach($links as [$href, $label, $icon])
                                        <a href="{{ $href }}" @if($active === $href) aria-current="page" @endif><x-icon :name="$icon" size="17"/><span>{{ $label }}</span></a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            </nav>
            <section class="admin-workspace-content" aria-label="{{ $title }}">{{ $slot }}</section>
        </div>
    </div>
</x-layouts.site>
