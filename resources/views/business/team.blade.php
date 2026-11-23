<x-layouts.site :title="'Team for '.$item->name">
    <div class="container page-shell narrow">
        <div class="page-intro">
            <div>
                <a class="text-link" href="/account/businesses/{{ $item->id }}">Manage business</a>
                <h1>{{ $item->name }} · Team</h1>
                <p>Only verified members who accept an invitation can represent this business.</p>
            </div>
        </div>
        <section class="panel form-stack">
            <h2>Invite a manager or editor</h2>
            <p class="field-note">Ask the person for their member account ID (shown in their public /users/ID profile link). They must accept in My businesses. This does not give access to earlier private customer conversations.</p>
            <form method="post" action="/account/businesses/{{ $item->id }}/team/invitations" class="form-stack">
                @csrf
                <label>Verified member ID<input name="invited_user_id" type="number" min="1" step="1" required value="{{ old('invited_user_id') }}"></label>
                <label>Role<select name="role"><option value="editor" @selected(old('role') === 'editor')>Editor — content only</option><option value="manager" @selected(old('role') === 'manager')>Manager — content and opted-in messages</option></select></label>
                <button class="button button-primary" type="submit">Send account invitation</button>
            </form>
        </section>
        <section class="panel">
            <h2>Current representatives</h2>
            @foreach($members as $member)
                <article class="item-row">
                    <div><strong>{{ $member->name }}</strong><p>{{ ucfirst($member->role) }}</p></div>
                    @if($member->role !== 'owner')
                        <form method="post" action="/account/businesses/{{ $item->id }}/team/{{ $member->id }}">
                            @csrf @method('DELETE')
                            <input type="hidden" name="expected_role" value="{{ $member->role }}">
                            <button type="submit" class="button button-outline">Remove access</button>
                        </form>
                    @endif
                </article>
            @endforeach
        </section>
        <section class="panel">
            <h2>Pending invitations</h2>
            @forelse($invitations as $invite)
                <article class="item-row">
                    <div><strong>{{ $invite->name }}</strong><p>{{ ucfirst($invite->role) }} · Expires {{ $invite->expires_at }}</p></div>
                    <form method="post" action="/account/businesses/{{ $item->id }}/team/invitations/{{ $invite->id }}">
                        @csrf @method('DELETE')
                        <button class="button button-outline" type="submit">Revoke invitation</button>
                    </form>
                </article>
            @empty <p>No pending invitations.</p> @endforelse
        </section>
    </div>
</x-layouts.site>
