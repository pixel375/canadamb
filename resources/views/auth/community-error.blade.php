<x-layouts.site title="Community sign-in">
    <div class="container page-shell auth-shell">
        <h1>{{ $unavailable ? 'Community sign-in is unavailable' : 'Sign-in could not be completed' }}</h1>
        <div class="panel form-stack">
            <p>{{ $unavailable ? 'You can still use your CanadaMB account on the main site.' : 'The request may have expired, been canceled, or already been used. Start a new sign-in request.' }}</p>
            <div class="inline-actions">
                @unless($unavailable)<a class="button button-primary" href="/auth/start">Try again</a>@endunless
                <a class="text-link" href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/login') }}">Sign in on CanadaMB</a>
            </div>
        </div>
    </div>
</x-layouts.site>
