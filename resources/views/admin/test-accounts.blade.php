<x-layouts.admin title="Prelaunch test accounts">
    <div class="page-intro"><div><h1>Prelaunch test accounts</h1><p>Verify a pending account after confirming who registered it.</p></div></div>
    <div class="form-stack">
        @forelse($accounts as $account)
            <article class="panel form-stack">
                <h2>{{ $account->name }}</h2>
                <p>{{ $account->email }}</p>
                <form method="post" action="/admin/test-accounts/{{ $account->id }}/verify">
                    @csrf
                    <button class="button button-primary">Verify account</button>
                </form>
            </article>
        @empty
            <p>No pending test accounts.</p>
        @endforelse
        {{ $accounts->links() }}
    </div>
    <a href="/admin/settings">Back to settings</a>
</x-layouts.admin>
