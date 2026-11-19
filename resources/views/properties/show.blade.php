@php($path = ($review ? '/admin/properties/' : '/account/properties/').$item->id)
<x-layouts.site :title="$item->title.' | Private property draft'">
    <div class="container page-shell narrow">
        <a class="text-link mb-4" href="{{ $review ? '/admin/properties' : '/account/properties' }}">Back to {{ $review ? 'private reviews' : 'property drafts' }}</a>
        <article class="panel">
            <p class="section-label">Private draft · {{ $item->location->name }}</p>
            <h1>{{ $item->title }}</h1>
            <p>{{ $item->transaction === 'rent' ? 'For rent' : 'For sale' }} · {{ \App\Models\PropertyListing::TYPES[$item->property_type] }} · CAD {{ number_format($item->price_cents / 100, 2) }}</p>
            <p>Review state · {{ str_replace('_', ' ', ucfirst($item->status)) }}</p>
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
                @if($item->available_on)<div><dt>Available</dt><dd>{{ $item->available_on->format('M j, Y') }}</dd></div>@endif
                @if($item->pets_allowed !== null)<div><dt>Pets</dt><dd>{{ $item->pets_allowed ? 'Allowed' : 'Restricted' }}</dd></div>@endif
            </dl>
        </article>
        @if($review && $item->status === 'submitted')
            <form method="post" action="{{ $path }}" class="panel form-stack">@csrf
                <label>Review note<textarea name="note" minlength="10" maxlength="1200" required rows="4"></textarea></label>
                <div class="inline-actions"><button class="button button-primary" name="action" value="review">Mark reviewed</button><button class="button button-outline" name="action" value="request-changes">Request changes</button></div>
            </form>
        @elseif(!$review)
            <div class="panel inline-actions">
                @if(in_array($item->status, ['draft', 'changes_requested'], true))
                    <a class="button button-outline" href="{{ $path }}/edit">Edit draft</a>
                    <form method="post" action="{{ $path }}/submit">@csrf<button class="button button-primary">Submit for private review</button></form>
                @endif
                <form method="post" action="{{ $path }}">@csrf @method('DELETE')<button class="button button-outline">Archive draft</button></form>
            </div>
        @endif
    </div>
</x-layouts.site>
