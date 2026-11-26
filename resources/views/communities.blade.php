<x-layouts.site :title="$site->isNetwork() ? 'Manitoba communities' : $site->location->name.' communities'">
    <div class="container page-shell">
        <div class="page-intro"><div><h1>{{ $site->isNetwork() ? 'Manitoba communities' : $site->location->name.' communities' }}</h1></div></div>
        @if($site->isNetwork())
            <section class="panel form-stack community-selector">
                <h2>Browse CanadaMB by location</h2>
                <form method="post" action="/communities/preference" class="form-stack">@csrf
                    <label for="community-location">Location</label>
                    <select id="community-location" name="location">
                        <option value="">All Manitoba</option>
                        @foreach($choices as $choice)
                            <option value="{{ $choice->slug }}" @selected($preferredCommunity?->id === $choice->id)>{{ $choice->name }}</option>
                        @endforeach
                    </select>
                    <button class="button button-primary">Set location</button>
                </form>
            </section>
        @else
            <section class="panel form-stack community-selector">
                <h2>You are browsing {{ $site->name }}</h2>
                <p>Explore all of Manitoba with the same account on CanadaMB.</p>
                <a class="button button-primary" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/communities') }}">Explore Manitoba</a>
            </section>
        @endif
        <section class="form-stack">
            <h2>{{ $site->isNetwork() ? 'Dedicated community websites' : 'Other community websites' }}</h2>
            <div class="place-grid">
                @forelse($portals as $portal)
                    <a class="panel home-portal-link" href="{{ \App\Support\NetworkUrl::to($portal) }}">
                        <span class="home-portal-accent" style="background:{{ $portal->accentCss() }}"></span>
                        <span><strong>Visit {{ $portal->name }}</strong><small>{{ $portal->location->name }}, Manitoba</small></span>
                        <x-icon name="arrow" size="17"/>
                    </a>
                @empty
                    <p>No other dedicated community websites are available yet.</p>
                @endforelse
            </div>
            <p>For all other Manitoba communities, use <a class="text-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/communities') }}">CanadaMB.ca</a>. <a class="text-link" href="/faq">How the network works</a></p>
        </section>
    </div>
</x-layouts.site>
