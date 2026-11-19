<x-layouts.site title="Authorize sign-in">
    <div class="container page-shell auth-shell">
        <h1>Authorize sign-in</h1>
        <div class="panel form-stack">
            <p>{{ $client->name }} requested access to your CanadaMB account.</p>
            <div class="inline-actions">
                <form method="post" action="{{ route('passport.authorizations.approve') }}">@csrf<input type="hidden" name="auth_token" value="{{ $authToken }}"><input type="hidden" name="state" value="{{ request('state') }}"><button class="button button-primary">Allow</button></form>
                <form method="post" action="{{ route('passport.authorizations.deny') }}">@csrf @method('DELETE')<input type="hidden" name="auth_token" value="{{ $authToken }}"><input type="hidden" name="state" value="{{ request('state') }}"><button class="button button-outline">Deny</button></form>
            </div>
        </div>
    </div>
</x-layouts.site>
