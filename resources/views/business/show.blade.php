@php($structured = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $business->name, 'url' => $canonical, 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $branches->first()->address, 'addressLocality' => $branches->first()->location->name, 'addressRegion' => 'MB', 'addressCountry' => 'CA']])
<x-layouts.site :title="$business->name.' | Business directory'" :canonical="$canonical" :structured-data="$structured">
    <div class="container page-shell narrow">
        <a class="text-link mb-4" href="/businesses">Business directory</a>
        <article class="panel">
            <p class="section-label">{{ \App\Support\BusinessDirectory::CATEGORIES[$business->category] }}</p>
            <h1>{{ $business->name }}</h1>
            @if($business->description)<p class="prose-text">{{ $business->description }}</p>@endif
            <div class="form-stack mt-4">
                @foreach($branches as $branch)
                    <div class="form-stack">
                        <div><h2>{{ $branch->location->name }}</h2><p>{{ $branch->address }}</p></div>
                        @if(in_array($branch->message_contact_user_id, $contactUsers, true) && $branch->message_contact_user_id !== auth()->id())
                            <div><x-message-contact-link :path="'/businesses/'.$business->slug.'/branches/'.$branch->id.'/contact'" label="Message business" :place="$branch->address.', '.$branch->location->name"/></div>
                        @endif
                    </div>
                @endforeach
                @if($business->hours)<div><h3>Hours</h3><p class="whitespace-pre-line">{{ $business->hours }}</p></div>@endif
                <div class="inline-actions">
                    @if($business->website)<a class="text-link" href="{{ $business->website }}" rel="nofollow ugc">Website <x-icon name="arrow" size="16"/></a>@endif
                    @if($business->phone)<span>{{ $business->phone }}</span>@endif
                    @if($business->public_email)<a class="text-link" href="mailto:{{ $business->public_email }}">Email business</a>@endif
                </div>
            </div>
        </article>
        @if(!$claimed)
            <section class="panel form-stack">
                <h2>Own this business?</h2>
                @auth
                    <form class="form-stack" method="post" action="/businesses/{{ $business->slug }}/claim">
                        @csrf
                        <label>Business contact email<input type="email" name="contact_email" required maxlength="254" value="{{ old('contact_email') }}"></label>
                        <label>How can we verify ownership?<textarea name="evidence" required minlength="30" maxlength="2400" rows="4">{{ old('evidence') }}</textarea><small class="field-note">Describe evidence you can share with our reviewers. Keep private documents and passwords out of this form.</small></label>
                        <div><button class="button button-primary">Request a claim</button></div>
                    </form>
                @else<a href="/login" class="text-link">Sign in to claim this listing <x-icon name="arrow" size="16"/></a>@endauth
            </section>
        @endif
        @if($updates->isNotEmpty())
            <div class="panel">
                <h2>Business updates</h2>
                @foreach($updates as $update)<article class="item-row"><div><p class="section-label">{{ ucfirst($update->type) }} · {{ $update->published_at->format('M j, Y') }}</p><h3>{{ $update->title }}</h3><p class="prose-text">{{ $update->body }}</p></div></article>@endforeach
                {{ $updates->links() }}
            </div>
        @endif
    </div>
</x-layouts.site>
