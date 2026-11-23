<x-layouts.site :title="'Manage locations · '.$profile->name">
    <div class="container page-shell narrow">
        <a class="text-link" href="/account/businesses/{{ $profile->id }}">Back to business management</a>
        <div class="page-intro"><div><h1>{{ $profile->name }} · Locations</h1><p>Request a new location, an address correction, or a closure. Nothing changes publicly until independently reviewed.</p></div></div>
        <section class="panel form-stack">
            <h2>Request an additional location</h2>
            <form class="form-stack" method="post" action="/account/businesses/{{ $profile->id }}/branches">
                @csrf<input type="hidden" name="action" value="add">
                @if($places->isNotEmpty())
                    <label>Manitoba community<select name="location_id" required><option value="">Choose community</option>
                        @foreach($places as $place)<option value="{{ $place->id }}">{{ $place->name }}</option>@endforeach
                    </select></label>
                @else
                    <p class="field-note">This request will add a location within the current community. Use CanadaMB.ca to choose another town.</p>
                @endif
                <label>Physical address<input name="proposed_address" maxlength="255" required></label>
                <label>Independent verification details<textarea name="evidence" minlength="30" maxlength="2400" required rows="3"></textarea></label>
                <button class="button button-primary">Submit location request</button>
            </form>
        </section>
        <section class="panel form-stack">
            <h2>Existing locations</h2>
            @foreach($branches as $branch)
                <div class="form-stack border-b border-gray-300 py-4">
                    <h3>{{ $branch->location->name }} · {{ $branch->address }}</h3>
                    <p>Status: {{ $branch->operational_status === 'open' ? 'Open' : ucfirst(str_replace('_', ' ', $branch->operational_status)) }}</p>
                    <form method="post" class="form-stack" action="/account/businesses/{{ $profile->id }}/branches">
                        @csrf<input type="hidden" name="action" value="correct_address">
                        <input type="hidden" name="business_location_id" value="{{ $branch->id }}">
                        <label>Correct this address<input name="proposed_address" maxlength="255" value="{{ $branch->address }}" required></label>
                        <label>Reason and independent evidence<textarea name="evidence" minlength="30" maxlength="2400" rows="2" required></textarea></label>
                        <button class="button button-outline">Request address correction</button>
                    </form>
                    <form method="post" class="form-stack" action="/account/businesses/{{ $profile->id }}/branches">
                        @csrf<input type="hidden" name="business_location_id" value="{{ $branch->id }}">
                        <label>Status change<select name="action">
                            @if($branch->operational_status === 'open')
                                <option value="temporarily_closed">Temporarily closed</option>
                                <option value="closed">Permanently closed</option>
                            @elseif($branch->operational_status === 'temporarily_closed')
                                <option value="reopen">Reopen</option>
                                <option value="closed">Permanently closed</option>
                            @else
                                <option value="reopen">Reopen</option>
                            @endif
                        </select></label>
                        <label>Reason for status update<textarea name="evidence" minlength="30" maxlength="2400" rows="2" required></textarea></label>
                        <button class="button button-outline">Request reviewed status change</button>
                    </form>
                </div>
            @endforeach
        </section>
        <section class="panel">
            <h2>Request history</h2>
            @forelse($requests as $change)
                <article class="item-row"><div>
                    <h3>{{ ucfirst(str_replace('_', ' ', $change->action)) }}</h3>
                    <p>{{ ucfirst($change->status) }} · {{ $change->created_at }}</p>
                </div>
                @if($change->status === 'pending')
                    <form method="post" action="/account/businesses/{{ $profile->id }}/branches/{{ $change->id }}">
                        @csrf @method('DELETE')
                        <input type="hidden" name="version" value="{{ $change->review_version }}">
                        <button class="button button-outline">Withdraw</button>
                    </form>
                @endif
                </article>
            @empty <p>No location change requests yet.</p> @endforelse
            {{ $requests->links() }}
        </section>
    </div>
</x-layouts.site>
