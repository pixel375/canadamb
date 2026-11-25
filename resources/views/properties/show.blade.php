@php($path = ($review ? '/admin/properties/' : '/account/properties/').$item->id)
<x-layouts.site :title="$item->title.' | Private property draft'">
    <div class="container page-shell property-private-detail">
        <a class="text-link mb-4" href="{{ $review ? '/admin/properties' : '/account/properties' }}">Back to {{ $review ? 'private reviews' : 'property drafts' }}</a>
        <article class="panel">
            <p class="section-label">Private draft · {{ $item->location->name }}</p>
            <h1>{{ $item->title }}</h1>
            <p>{{ $item->transaction === 'rent' ? 'For rent' : 'For sale' }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }} · CAD {{ number_format($item->price_cents / 100, 2) }}</p>
            <p><span class="member-listing-status" data-status="{{ $item->status }}">{{ ucfirst(str_replace('_', ' ', $item->status)) }}</span></p>
            <div class="property-private-notice">
                <strong>Authority and review status — private</strong>
                <p>Submitted property information requires an owner or authorized-representative declaration and independent reviewer checks. This information does not appear in public listings.</p>
            </div>
            @if($item->review_note)<p class="notice">{{ $item->review_note }}</p>@endif
            <p class="prose-text">{{ $item->description }}</p>
            @if($item->photos->isNotEmpty())
                <div class="listing-grid">@foreach($item->photos as $photo)<img src="{{ $path }}/media/{{ $photo->id }}" alt="Property photo {{ $loop->iteration }}" loading="lazy">@endforeach</div>
            @endif
            <dl class="job-detail-facts mt-3">
                @foreach(['bedrooms' => 'Bedrooms', 'bathrooms' => 'Bathrooms', 'floor_area' => 'Floor area (sq ft)', 'acreage' => 'Acreage', 'year_built' => 'Year built', 'private_address' => 'Street address', 'legal_land_description' => 'Legal land description'] as $field => $label)
                    @if($item->{$field} !== null)<div><dt>{{ $label }}</dt><dd>{{ $item->{$field} }}</dd></div>@endif
                @endforeach
                <div><dt>Declared seller role</dt><dd>{{ \App\Models\PropertyListing::SELLERS[$item->seller_type] }}</dd></div>
                @if($item->available_on)<div><dt>Available</dt><dd>{{ $item->available_on->format('M j, Y') }}</dd></div>@endif
                @if($item->pets_allowed !== null)<div><dt>Pets</dt><dd>{{ $item->pets_allowed ? 'Allowed' : 'Restricted' }}</dd></div>@endif
            </dl>
        </article>
        @if($review && in_array($item->status, ['submitted', 'reviewed'], true))
            <form method="post" action="{{ $path }}" class="panel form-stack">
                @csrf
                <input type="hidden" name="version" value="{{ $item->contentVersion() }}">
                <h2>{{ $item->status === 'submitted' ? 'Private review checklist' : 'Reopen a reviewed draft' }}</h2>
                <p class="field-note">Review is internal only. No action on this page authorizes or publishes an advertisement.</p>
                @if($item->status === 'submitted')
                    <fieldset class="property-review-checks">
                        <legend>Check each item before marking reviewed</legend>
                        <label><input type="checkbox" name="owner_authority_checked" value="1"> I have independently checked the owner's or authorized agent's advertising authority.</label>
                        <label><input type="checkbox" name="seller_role_checked" value="1"> I have checked the seller role and any applicable brokerage registration information.</label>
                        <label><input type="checkbox" name="property_details_checked" value="1"> I have reviewed the property details, pricing, and location.</label>
                        <label><input type="checkbox" name="media_checked" value="1"> I have reviewed the photos for suitability and privacy.</label>
                    </fieldset>
                @endif
                <label>Private review note<textarea name="note" minlength="10" maxlength="1200" required rows="4">{{ old('note') }}</textarea></label>
                <div class="inline-actions">
                    @if($item->status === 'submitted')
                        <button class="button button-primary" name="action" value="review">Mark privately reviewed</button>
                    @endif
                    <button class="button button-outline" name="action" value="request-changes">Request changes</button>
                </div>
            </form>
        @elseif(!$review)
            <div class="panel property-private-actions">
                @if(in_array($item->status, ['draft', 'changes_requested'], true))
                    <a class="button button-outline" href="{{ $path }}/edit">Edit draft</a>
                    <form method="post" action="{{ $path }}/submit" class="form-stack property-attestation">
                        @csrf
                        <input type="hidden" name="version" value="{{ $item->contentVersion() }}">
                        <h2>Submit for private review</h2>
                        <p class="field-note">Declare your authority to submit the property. This declaration alone does not authorize public advertising.</p>
                        <label>Your capacity
                            <select name="authorization_capacity" required>
                                <option value="">Select your authority</option>
                                <option value="owner" @selected(old('authorization_capacity') === 'owner')>I am the property owner</option>
                                <option value="authorized-agent" @selected(old('authorization_capacity') === 'authorized-agent')>I am acting with the owner's authorization</option>
                            </select>
                        </label>
                        <label class="property-consent-check"><input type="checkbox" name="advertising_consent" value="1" required> I confirm I have the owner's authority to submit this information for private review, and understand that public advertising requires separate consent verification and approval.</label>
                        <button class="button button-primary" type="submit">Submit for private review</button>
                    </form>
                @endif
                @if(in_array($item->status, ['submitted', 'reviewed'], true))
                    <details class="seller-remove-listing"><summary>Return to draft for editing</summary>
                        <form method="post" action="{{ $path }}/withdraw-review" class="form-stack mt-2">@csrf
                            <input type="hidden" name="version" value="{{ $item->contentVersion() }}">
                            <p class="field-note">Withdraw the current review and keep your photos. You must submit a new authority declaration before review resumes.</p>
                            <button class="button button-outline">Return to private draft</button>
                        </form>
                    </details>
                @endif
                <details class="seller-remove-listing"><summary>Archive / withdraw draft</summary><p class="field-note">This removes the draft from your workspace and deletes its stored photos. It cannot be restored here.</p>
                    <form method="post" action="{{ $path }}">@csrf @method('DELETE')<button class="button button-outline" type="submit">Confirm archive / withdrawal</button></form>
                </details>
            </div>
        @endif
    </div>
</x-layouts.site>
