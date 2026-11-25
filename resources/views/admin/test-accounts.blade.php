<x-layouts.admin title="Prelaunch test accounts">
    <div class="page-intro"><div><h1>Prelaunch test accounts</h1><p>Verify a pending account after confirming who registered it.</p></div></div>
    <form method="get" action="/admin/test-accounts" class="panel form-stack mb-4">
        <p>{{ $pending }} pending · {{ $verified }} verified test accounts</p>
        <div class="form-grid"><label>Status<select name="status"><option value="pending" @selected(($filters['status'] ?? 'pending') === 'pending')>Pending verification</option><option value="verified" @selected(($filters['status'] ?? '') === 'verified')>Verified</option></select></label><label>Search test accounts<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email"></label></div>
        <div class="inline-actions"><button class="button button-primary">Filter accounts</button><a class="button button-outline" href="/admin/test-accounts">Reset</a></div>
    </form>
    <div class="form-stack">
        @forelse($accounts as $account)
            <article class="panel form-stack">
                <h2>{{ $account->name }}</h2>
                <p>{{ $account->email }}</p>
                <p class="field-note">Registered {{ $account->created_at->format('M j, Y') }}</p>
                @if($account->hasVerifiedEmail())
                    <p class="field-note">Verified {{ $account->email_verified_at->format('M j, Y') }}</p>
                @else
                <form method="post" action="/admin/test-accounts/{{ $account->id }}/verify">
                    @csrf
                    <button class="button button-primary">Verify account</button>
                </form>
                @endif
            </article>
        @empty
            <p>{{ filled($filters['q'] ?? null) || ($filters['status'] ?? 'pending') === 'verified' ? 'No test accounts match these filters.' : 'No pending test accounts.' }}</p>
        @endforelse
        {{ $accounts->links() }}
    </div>
    <a href="/admin/settings">Back to settings</a>
</x-layouts.admin>
