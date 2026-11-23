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
        <section class="panel form-stack">
            <h2>Propose a new owner</h2>
            <p>Only someone who already owns this business can propose a transfer. The verified successor must accept it and a separate network reviewer must confirm independent proof. Your existing private conversations are never handed over automatically.</p>
            <form method="post" action="/account/businesses/{{ $item->id }}/ownership-transfer" class="form-stack">
                @csrf
                <label>Successor's verified member account ID<input type="number" min="1" name="proposed_owner_id" required value="{{ old('proposed_owner_id') }}"></label>
                <label>Evidence you can provide for independent authorization
                    <textarea name="evidence" minlength="30" maxlength="2400" rows="4" required>{{ old('evidence') }}</textarea>
                </label>
                <button class="button button-outline">Request reviewed ownership transfer</button>
            </form>
            <p><a class="text-link" href="/account/ownership-cases">Review ownership request statuses</a></p>
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
