@php($site = request()->attributes->get('site'))
<x-layouts.site :title="$business->exists ? 'Manage '.$business->name : 'Add a business'">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><h1>{{ $business->exists ? $business->name : 'Add a business' }}</h1><a class="text-link mt-2" href="/account/businesses">My businesses</a></div></div>
        @if($business->exists)
            <section class="panel form-stack" aria-label="Business overview">
                <div class="inline-actions"><span class="member-listing-status" data-status="{{ $business->status }}">{{ ucfirst($business->status) }}</span><span class="field-note">{{ $business->status === 'active' ? 'Your profile has been approved.' : 'This profile is not currently public.' }}</span></div>
                <a class="button button-outline" href="/account/businesses/{{ $business->id }}/team">Business team and permissions</a>
                @if(\App\Support\CommercialRecords::enabled() && $business->status === 'active' && $business->members()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists())<a class="button button-outline" href="/account/businesses/{{ $business->id }}/advertising">Advertising requests</a>@endif
                @if(\App\Support\BusinessChanges::enabled() && $business->status === 'active' && $business->members()->whereKey(auth()->id())->wherePivotIn('role', ['owner', 'manager'])->exists())
                    <a class="button button-outline" href="/account/businesses/{{ $business->id }}/changes">Manage branches and contact verification</a>
                @endif
                @if(\App\Support\BusinessInquirySettings::enabled() && $business->status === 'active' && $business->members()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists())
                    @php($inquiryPreferences = \App\Support\BusinessInquirySettings::preferences($business, auth()->id()))
                    <form class="form-stack" method="post" action="/account/businesses/{{ $business->id }}/inquiry-settings">@csrf
                        <input type="hidden" name="version" value="{{ \App\Support\BusinessInquirySettings::version($business, auth()->id(), $inquiryPreferences) }}">
                        <h2>Private inquiries</h2><p class="field-note">New inquiries go to your personal account. Turning them off does not delete existing conversations. A new owner must opt in separately.</p>
                        <label>Business inquiries<select name="business_inquiries"><option value="0">Off</option><option value="1" @selected($inquiryPreferences['business_inquiries'])>On</option></select></label>
                        <label>Job inquiries<select name="job_inquiries"><option value="0">Off</option><option value="1" @selected($inquiryPreferences['job_inquiries'])>On</option></select></label>
                        <button class="button button-outline">Save inquiry preferences</button>
                    </form>
                @endif
                <h2>Branches on this portal</h2>
                @forelse($branches as $branch)<div><strong>{{ $branch->location->name }}</strong><p>{{ $branch->address }} @if(!$branch->isOpen()) · Closed @endif</p></div>@empty<p>No branches on this portal. Manage other locations on CanadaMB.ca.</p>@endforelse
                @if($business->status === 'active' && app(\App\Support\BusinessDirectory::class)->enabled($site) && $branches->contains(fn ($branch) => $branch->isOpen()))<a class="text-link" href="/businesses/{{ $business->slug }}">View public profile <x-icon name="arrow" size="16"/></a>@endif
            </section>
        @endif
        <form class="panel form-stack" method="post" action="{{ $business->exists ? '/account/businesses/'.$business->id : '/account/businesses' }}">
            @csrf
            @unless($business->exists)<x-shared-publication-note/>@endunless
            <h2>{{ $business->exists ? 'Profile information' : 'Business information' }}</h2>
            @if($business->exists)@method('PUT')<input type="hidden" name="version" value="{{ old('version', $business->profileVersion()) }}">
            @else
                <label>Business name<input name="name" value="{{ old('name') }}" required maxlength="140"></label>
                <label>Category<select name="category" required><option value="">Choose a category</option>@foreach($categories as $key => $category)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $category }}</option>@endforeach</select></label>
                <label>Public business address<input name="address" value="{{ old('address') }}" maxlength="255" required></label>
                @if($site->isNetwork())<label>Location<select name="location_id" required><option value="">Choose a Manitoba community</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) old('location_id', $suggestedLocation?->id ?? null) === $place->id)>{{ $place->name }}</option>@endforeach</select></label>
                @else
                    <label>Location<input value="{{ $site->location->name }}" readonly></label>
                    <p class="field-note">Posting for another Manitoba location? <a href="{{ \App\Support\NetworkUrl::to(\App\Support\NetworkUrl::main(), '/account/businesses/new') }}">Visit CanadaMB.ca to choose your community.</a></p>
                @endif
            @endif
            <label>Description<textarea data-character-count name="description" rows="5" maxlength="2500">{{ old('description', $business->description) }}</textarea><small class="field-note" data-character-feedback></small></label>
            <label>Hours<textarea name="hours" rows="3" maxlength="1000">{{ old('hours', $business->hours) }}</textarea></label>
            <label>Website<input type="url" name="website" maxlength="255" value="{{ old('website', $business->website) }}"></label>
            <p class="field-note">The following contact details will appear on the public directory profile. Do not enter private contact information.</p>
            <label>Public contact email<input type="email" name="public_email" maxlength="254" value="{{ old('public_email', $business->public_email) }}"></label>
            <label>Public phone<input type="tel" name="phone" maxlength="32" value="{{ old('phone', $business->phone) }}"></label>
            <div><button class="button button-primary">{{ $business->exists ? 'Save profile' : 'Submit for review' }}</button></div>
        </form>
        @if($business->status === 'active' && app(\App\Support\JobBoard::class)->enabled($site)
            && $business->members()->whereKey(auth()->id())->wherePivotIn('role', ['owner', 'manager'])->exists())
            <div class="panel"><a href="/account/businesses/{{ $business->id }}/jobs" class="button button-outline">Manage job postings</a></div>
        @endif
        @if($business->status === 'active' && $branches->contains(fn ($branch) => $branch->isOpen()))
            <form class="panel form-stack" method="post" action="/account/businesses/{{ $business->id }}/updates">@csrf
                <x-shared-publication-note/>
                <h2>Publish a business update</h2>
                <label>Branch<select name="business_location_id" required>@foreach($branches->filter(fn ($branch) => $branch->isOpen()) as $branch)<option value="{{ $branch->id }}" @selected((int) old('business_location_id') === $branch->id)>{{ $branch->location->name }} · {{ $branch->address }}</option>@endforeach</select></label>
                <label>Type<select name="type" required>@foreach(['announcement', 'promotion', 'update'] as $type)<option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
                <label>Title<input name="title" value="{{ old('title') }}" maxlength="140" required></label>
                <label>Update<textarea name="body" rows="5" maxlength="3000" required>{{ old('body') }}</textarea></label>
                <div><button class="button button-primary">Submit update for review</button></div>
            </form>
        @endif
        @if($business->exists)<div class="panel"><a class="button button-outline" href="/account/businesses/{{ $business->id }}/updates">Manage all business updates</a></div>@endif
        @if($business->exists && ($recentUpdates ?? collect())->isNotEmpty())
            <section class="panel mt-4"><h2>Recent business updates</h2><p class="field-note">Only approved updates are visible publicly.</p>
                @foreach($recentUpdates as $update)<article class="item-row"><div><h3>{{ $update->title }}</h3><p>{{ ucfirst($update->type) }} · {{ \Illuminate\Support\Carbon::parse($update->created_at)->format('M j, Y') }}</p></div><span class="member-listing-status" data-status="{{ $update->status }}">{{ ucfirst($update->status) }}</span></article>@endforeach
            </section>
        @endif
    </div>
</x-layouts.site>
