@php($path = ($review ? '/admin/properties/' : '/account/properties/').$item->id)
<x-layouts.site :title="$item->title.' | Private property draft'">
    <div class="container page-shell narrow">
        <a class="text-link mb-4" href="{{ $review ? '/admin/properties' : '/account/properties' }}">Back to {{ $review ? 'private reviews' : 'property drafts' }}</a>
        <article class="panel">
            <p class="section-label">Private draft · {{ $item->location->name }}</p>
            <h1>{{ $item->title }}</h1>
            <p>{{ $item->transaction === 'rent' ? 'For rent' : 'For sale' }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }} · CAD {{ number_format($item->price_cents / 100, 2) }}</p>
            <p>Review state · {{ str_replace('_', ' ', ucfirst($item->status)) }}</p>
            <div class="property-private-notice">
                <strong>Advertising authority and review status — private</strong>
                <p>
                    @if($item->authorization_attested_at)
                        Self-attested {{ $item->authorization_capacity === 'owner' ? 'by owner' : 'by an authorized representative' }}
                        on {{ $item->authorization_attested_at->format('M j, Y') }}.
                    @else
                        Advertising authority has not been attested.
                    @endif
                    @if($item->review_checklist_completed_at)
                        Internal review checklist completed {{ $item->review_checklist_completed_at->format('M j, Y') }}.
                    @else
                        Internal review checklist not completed.
                    @endif
                </p>
                <p>Private review is not a public advertising approval. These details do not appear on the public Real Estate page.</p>
            </div>
            @if($item->review_note)<p class="notice">{{ $item->review_note }}</p>@endif
            <p class="prose-text">{{ $item->description }}</p>
            @if($item->photos->isNotEmpty())
                <div class="listing-grid">@foreach($item->photos as $photo)<img src="{{ $path }}/media/{{ $photo->id }}" alt="Property photo {{ $loop->iteration }}" loading="lazy">@endforeach</div>
            @endif
            <dl class="form-grid mt-3">
                @foreach(['bedrooms' => 'Bedrooms', 'bathrooms' => 'Bathrooms', 'floor_area' => 'Floor area (sq ft)', 'acreage' => 'Acreage', 'year_built' => 'Year built', 'private_address' => 'Street address', 'legal_land_description' => 'Legal land description'] as $field => $label)
                    @if($item->{$field} !== null)<div><dt>{{ $label }}</dt><dd>{{ $item->{$field} }}</dd></div>@endif
                @endforeach
                <div><dt>Declared seller role</dt><dd>{{ \App\Models\PropertyListing::SELLERS[$item->seller_type] }}</dd></div>
                @if($item->brokerage_name)
                    <div><dt>Brokerage (private)</dt><dd>{{ $item->brokerage_name }}</dd></div>
                @endif
                @if($item->registration_reference)
                    <div><dt>Registration reference (private)</dt><dd>{{ $item->registration_reference }}</dd></div>
                @endif
                @if($item->available_on)<div><dt>Available</dt><dd>{{ $item->available_on->format('M j, Y') }}</dd></div>@endif
                @if($item->pets_allowed !== null)<div><dt>Pets</dt><dd>{{ $item->pets_allowed ? 'Allowed' : 'Restricted' }}</dd></div>@endif
            </dl>
        </article>
        @if($review && in_array($item->status, ['submitted', 'reviewed'], true))
            <form method="post" action="{{ $path }}" class="panel form-stack">
                @csrf
                <h2>{{ $item->status === 'submitted' ? 'Private review checklist' : 'Reopen a reviewed draft' }}</h2>
                <p class="field-note">Review is internal only. No action on this page authorizes or publishes a Real Estate advertisement.</p>
                @if($item->status === 'submitted')
                    <fieldset class="property-review-checks">
                        <legend>Checks required before marking reviewed</legend>
                        <label><input type="checkbox" name="owner_authority_checked" value="1"> I have independently checked the owner's or authorized agent's advertising authority, not just the self-attestation.</label>
                        <label><input type="checkbox" name="seller_role_checked" value="1"> I have checked the seller's role and, where relevant, brokerage and registration information.</label>
                        <label><input type="checkbox" name="property_details_checked" value="1"> I have checked the property's details, pricing and location.</label>
                        <label><input type="checkbox" name="media_checked" value="1"> I have checked photographs for advertising suitability and personal information.</label>
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
                        <h2>Submit for private review</h2>
                        <p class="field-note">Before an internal reviewer can consider this property, confirm your authority to provide the information. This declaration alone does not authorize public advertising.</p>
                        <label>Your capacity
                            <select name="authorization_capacity" required>
                                <option value="">Select your authority</option>
                                <option value="owner" @selected(old('authorization_capacity') === 'owner')>I am the property owner</option>
                                <option value="authorized-agent" @selected(old('authorization_capacity') === 'authorized-agent')>I am acting with the owner's authorization</option>
                            </select>
                        </label>
                        <label class="property-consent-check"><input type="checkbox" name="advertising_consent" value="1" required> I confirm that I have authority from the property owner to submit the property for review, and that any eventual public advertising requires the owner's consent and separate verification.</label>
                        <button class="button button-primary" type="submit">Submit for private review</button>
                    </form>
                @endif
                <form method="post" action="{{ $path }}">
                    @csrf
                    @method('DELETE')
                    <button class="button button-outline" type="submit">Archive / withdraw draft</button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.site>
