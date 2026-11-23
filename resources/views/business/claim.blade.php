<x-layouts.site title="Business claim">
    <div class="container page-shell narrow">
        <a class="text-link" href="/account/businesses">My businesses</a>
        <div class="page-intro"><div><h1>Claim for {{ $business->name }}</h1><p>{{ ucfirst($item->status) }}</p></div></div>
        @if($reason)<div class="panel"><h2>Review outcome</h2><p class="prose-text">{{ $reason }}</p></div>@endif
        @if($canRevise)
            <form class="panel form-stack" method="post" action="/account/business-claims/{{ $item->id }}">
                @csrf @method('PUT')
                <input type="hidden" name="version" value="{{ $version }}">
                <h2>Update private evidence</h2>
                <p>Describe evidence that can be checked independently. Keep documents, passwords and access codes out of this form.</p>
                <label>Business contact email<input name="contact_email" type="email" required maxlength="254" value="{{ old('contact_email', $item->contact_email) }}"></label>
                <label>Verification evidence<textarea name="evidence" required minlength="30" maxlength="2400" rows="5">{{ $evidence }}</textarea></label>
                <div><button class="button button-primary">Save claim evidence</button></div>
            </form>
        @else
            <section class="panel"><h2>Submitted evidence</h2><p class="prose-text">{{ $evidence }}</p></section>
        @endif
        @if($version)
            <form class="panel" method="post" action="/account/business-claims/{{ $item->id }}">
                @csrf @method('DELETE')
                <input type="hidden" name="version" value="{{ $version }}">
                <button class="button button-outline">Withdraw claim</button>
            </form>
        @endif
    </div>
</x-layouts.site>
