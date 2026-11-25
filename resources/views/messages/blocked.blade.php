<x-layouts.site title="Blocked members">
    <div class="container page-shell message-workspace">
        <div class="page-intro"><div><a class="text-link" href="/account/settings">Account settings</a><h1>Blocked members</h1><p>Blocks apply to private messages across CanadaMB communities. They do not hide public posts.</p></div><a class="button button-outline" href="/account/messages">Messages</a></div>
        <form method="get" action="/account/blocked-members" class="panel form-stack mb-4">
            <label>Search blocked members<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Member name"></label>
            <div class="inline-actions"><button class="button button-primary">Search</button><a class="button button-outline" href="/account/blocked-members">Clear search</a></div>
        </form>
        <p class="field-note">{{ $members->total() }} matching · {{ $total }} blocked across the network</p>
        <section class="panel">
            @forelse($members as $member)
                <article class="item-row blocked-member-row"><div class="listing-seller-identity"><span class="message-avatar"><x-icon name="person" size="22"/></span><div><strong>{{ $member->name }}</strong><p class="field-note">Blocked by you</p></div></div>
                    <details><summary>Unblock member</summary><form method="post" action="/account/blocked-members/{{ $member->id }}" class="mt-2">@csrf @method('DELETE')<button class="button button-outline">Confirm unblock</button></form></details>
                </article>
            @empty<p>{{ $total ? 'No blocked members match your search.' : 'You have not blocked any members.' }}</p>@endforelse
            {{ $members->links() }}
        </section>
    </div>
</x-layouts.site>
