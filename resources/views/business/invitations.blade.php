<x-layouts.site title="Business invitations">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Business invitations</h1><p>Accepting grants future staff access to that business. It does not transfer ownership or historical customer messages.</p></div><a class="text-link" href="/account/businesses">My businesses</a></div>
        <section class="panel">
            @forelse($items as $item)
                <article class="item-row">
                    <div><h2>{{ $item->name }}</h2><p>Invited as {{ $item->role }} · Expires {{ $item->expires_at }}</p></div>
                    <div class="inline-actions">
                        <form method="post" action="/account/business-invitations/{{ $item->id }}/respond">
                            @csrf<input type="hidden" name="decision" value="accept">
                            <button class="button button-primary" type="submit">Accept</button>
                        </form>
                        <form method="post" action="/account/business-invitations/{{ $item->id }}/respond">
                            @csrf<input type="hidden" name="decision" value="decline">
                            <button class="button button-outline" type="submit">Decline</button>
                        </form>
                    </div>
                </article>
            @empty <p>No outstanding business invitations.</p> @endforelse
        </section>
    </div>
</x-layouts.site>
