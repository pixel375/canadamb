<x-layouts.site :title="$item->name.' team'">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $item->id }}">Business dashboard</a><h1>{{ $item->name }} team</h1><p>Team access applies to the business profile across its branches.</p></div></div>
        <section class="panel form-stack"><h2>Team members ({{ $members->count() }})</h2>
            @foreach($members as $member)
                <article class="item-row business-team-row"><div><strong>{{ $member->name }}</strong><p>{{ ucfirst($member->pivot->role) }} @if($member->id === auth()->id()) · You @endif</p></div>
                @if($isOwner && $item->status === 'active' && in_array($member->pivot->role, ['manager','editor'], true))
                    <details><summary>Manage access</summary><form class="form-stack mt-2" method="post" action="/account/businesses/{{ $item->id }}/team/{{ $member->id }}">@csrf @method('PUT')<label>Role<select name="role"><option value="manager" @selected($member->pivot->role === 'manager')>Manager</option><option value="editor" @selected($member->pivot->role === 'editor')>Editor</option></select></label><button class="button button-outline">Save role</button></form>
                    <form class="form-stack mt-3" method="post" action="/account/businesses/{{ $item->id }}/team/{{ $member->id }}">@csrf @method('DELETE')<p class="field-note">Removal immediately revokes business access.</p><button class="button button-outline">Confirm remove member</button></form></details>
                @endif
                </article>
            @endforeach
        </section>
        <section class="panel form-stack"><h2>Permissions</h2><dl class="job-detail-facts"><div><dt>Owner</dt><dd>Manage team, profile, updates and jobs.</dd></div><div><dt>Manager</dt><dd>Manage profile, updates and jobs.</dd></div><div><dt>Editor</dt><dd>Manage profile and updates only.</dd></div></dl><p class="field-note">Ownership changes and disputes require independent review. Owners cannot be removed or demoted here.</p><a class="text-link" href="/contact?topic=business">Contact about ownership</a></section>
        @if($isOwner && $item->status === 'active')
            <section class="panel form-stack"><h2>Add a team member</h2><p>Grant access to an existing verified CanadaMB account. Confirm the account holder agrees before granting access. No invitation email is sent.</p>
                <form method="post" action="/account/businesses/{{ $item->id }}/team" class="form-stack">@csrf
                    <label>Account email<input name="email" type="email" maxlength="254" required autocomplete="off"></label>
                    <label>Role<select name="role"><option value="editor">Editor</option><option value="manager">Manager</option></select></label>
                    <label class="checkbox"><input name="consent" type="checkbox" value="1" required> I have confirmed this account holder agrees to the role.</label>
                    <button class="button button-primary">Grant access</button>
                </form>
                <p class="field-note">Maximum 20 team members, including the owner.</p>
            </section>
        @endif
    </div>
</x-layouts.site>
