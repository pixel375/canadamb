<x-layouts.site title="Manage business updates">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/businesses/{{ $item->id }}">Business dashboard</a><h1>Manage business updates</h1><p>{{ $item->name }} · Updates on this portal</p></div></div>
        <form class="panel saved-search" method="get"><label>Search updates<input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></label><label>Status<select name="status"><option value="">All statuses</option>@foreach(['pending','approved','rejected','withdrawn'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label><button class="button button-primary">Filter updates</button></form>
        <section class="panel form-stack">
            @forelse($updates as $update)
                <article class="admin-review-card form-stack">
                    <h2>{{ $update->title }}</h2><p>{{ $update->branch->location->name }} · {{ ucfirst($update->type) }} · {{ ucfirst($update->status) }}</p><p class="prose-text">{{ $update->body }}</p>
                    @if(($canManageAll || $update->author_id === auth()->id()) && in_array($update->status, ['pending','approved','rejected'], true))
                        @if($item->status === 'active' && $update->branch->isOpen())
                            <details><summary>Edit and submit for review</summary>
                                <form method="post" class="form-stack mt-2" action="/account/businesses/{{ $item->id }}/updates/{{ $update->id }}">@csrf @method('PUT')
                                    <input type="hidden" name="version" value="{{ $update->reviewVersion() }}">
                                    <label>Type<select name="type">@foreach(['announcement','promotion','update'] as $type)<option value="{{ $type }}" @selected($update->type === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
                                    <label>Title<input name="title" maxlength="140" required value="{{ $update->title }}"></label>
                                    <label>Update<textarea name="body" required maxlength="3000" rows="5">{{ $update->body }}</textarea></label>
                                    <p class="field-note">Changed approved content is hidden until independently reviewed again.</p><button class="button button-primary">Save revision</button>
                                </form>
                            </details>
                        @endif
                        <details><summary>Withdraw update</summary><form method="post" class="form-stack mt-2" action="/account/businesses/{{ $item->id }}/updates/{{ $update->id }}/withdraw">@csrf<input type="hidden" name="version" value="{{ $update->reviewVersion() }}"><p>Remove this update from public view while retaining its history.</p><button class="button button-outline">Confirm withdrawal</button></form></details>
                    @endif
                </article>
            @empty<p>No matching updates.</p>@endforelse
            {{ $updates->links() }}
        </section>
    </div>
</x-layouts.site>
