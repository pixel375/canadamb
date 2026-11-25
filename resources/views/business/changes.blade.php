<x-layouts.site title="Business branches and contact review">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $item->id }}">Business dashboard</a><h1>Branches and contact review</h1><p>{{ $item->name }}</p></div></div>
        <section class="panel form-stack"><h2>Add a branch</h2><p>New addresses require independent review before appearing publicly.</p>
            <form class="form-stack" method="post" action="/account/businesses/{{ $item->id }}/changes">@csrf<input type="hidden" name="kind" value="branch-add">
                @if(request()->attributes->get('site')->isNetwork())<label>Community<select name="location_id" required><option value="">Choose a community</option>@foreach($places as $place)<option value="{{ $place->id }}">{{ $place->name }}</option>@endforeach</select></label>
                @else<p>{{ request()->attributes->get('site')->location->name }}</p>@endif
                <label>Public business address<input name="address" maxlength="255" required></label><button class="button button-primary">Request new branch</button>
            </form>
        </section>
        <section class="panel form-stack"><h2>Correct a branch address</h2><p>For corrections within the same community. Contact support for closures or relocation to a different community.</p>
            @forelse($branches as $branch)<details><summary>{{ $branch->location->name }} · {{ $branch->address }}</summary>
                <form class="form-stack mt-2" method="post" action="/account/businesses/{{ $item->id }}/changes">@csrf<input type="hidden" name="kind" value="branch-address"><input type="hidden" name="branch_id" value="{{ $branch->id }}">
                    <label>Corrected public address<input name="address" maxlength="255" required value="{{ $branch->address }}"></label><button class="button button-outline">Request correction</button>
                </form></details>
            @empty<p>No branches in this community.</p>@endforelse
        </section>
        @if($isOwner)<section class="panel form-stack"><h2>Public contact email review</h2><p>Current email: {{ $item->public_email ?? 'Not set' }}</p><p>Reviewers must independently confirm control of the exact address. Changing it invalidates prior approval. No public badge or automated email is issued.</p>
            @if($item->public_email)<form method="post" action="/account/businesses/{{ $item->id }}/changes">@csrf<input type="hidden" name="kind" value="contact"><button class="button button-outline">Request contact verification</button></form>@endif
        </section>@endif
        <section class="panel form-stack"><h2>Change request history</h2>@forelse($items as $row)
            <article class="admin-review-card form-stack"><h3>{{ ucfirst(str_replace('-', ' ', $row->kind)) }}</h3><p>{{ $row->value }} · {{ ucfirst($row->status) }}</p>
                @if($row->status === 'pending' && (int) $row->requester_id === auth()->id())<form method="post" action="/account/businesses/{{ $item->id }}/changes/{{ $row->id }}/withdraw">@csrf<button class="button button-outline">Withdraw request</button></form>@endif
            </article>
        @empty<p>No requests submitted.</p>@endforelse{{ $items->links() }}</section>
    </div>
</x-layouts.site>
