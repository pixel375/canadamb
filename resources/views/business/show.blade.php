@php($structured = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $business->name, 'url' => $canonical, 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $branches->first()->address, 'addressLocality' => $branches->first()->location->name, 'addressRegion' => 'MB', 'addressCountry' => 'CA']])
<x-layouts.site :title="$business->name.' | Business directory'" :canonical="$canonical" :structured-data="$structured">
    <div class="container page-shell">
        <a class="text-link mb-4" href="/businesses"><x-icon name="building" size="16"/> Business directory</a>
        <header class="panel public-business-hero">
            <span class="public-business-avatar"><x-icon name="building" size="40"/></span>
            <div class="public-business-heading">
                <p class="section-label">{{ \App\Support\BusinessDirectory::CATEGORIES[$business->category] }}</p>
                <h1>{{ $business->name }}</h1>
                <p>{{ $branches->pluck('location.name')->unique()->implode(' · ') }}</p>
                @if($claimed)<p class="field-note"><x-icon name="check" size="16"/> Owner-managed profile</p>@endif
            </div>
            @if($managesProfile)<a class="button button-outline" href="/account/businesses/{{ $business->id }}">Manage profile</a>@endif
        </header>
        <div class="public-profile-layout">
            <div class="public-profile-main">
                <section class="panel">
                    <h2>About this business</h2>
                    @if($business->description)<p class="prose-text">{{ $business->description }}</p>@else<p class="field-note">No description has been provided.</p>@endif
                </section>
                @if($openJobs->isNotEmpty())
                    <section class="panel"><h2>Current job opportunities</h2>
                        @foreach($openJobs as $item)<x-job-result :item="$item" :show-description="true"/>@endforeach
                    </section>
                @endif
                @if($updates->isNotEmpty())
                    <section class="panel">
                        <h2>Business updates</h2>
                        @foreach($updates as $update)<article class="item-row"><div><p class="section-label">{{ ucfirst($update->type) }} · {{ $update->published_at->format('M j, Y') }}</p><h3>{{ $update->title }}</h3><p class="prose-text">{{ $update->body }}</p><x-content-report-link type="business-update" :target="$update->id"/></div></article>@endforeach
                        {{ $updates->links() }}
                    </section>
                @endif
            </div>
            <aside class="public-profile-aside">
                <section class="panel form-stack">
                    <h2>Contact and locations</h2><x-content-report-link type="business" :target="$business->id"/>
                    @foreach($branches as $branch)<div class="business-contact-row"><x-icon name="pin" size="20"/><div><h3>{{ $branch->location->name }}</h3><p>{{ $branch->address }}</p></div></div>@endforeach
                    @if($business->hours)<div class="business-contact-row"><x-icon name="clock" size="20"/><div><h3>Hours</h3><p class="whitespace-pre-line">{{ $business->hours }}</p></div></div>@endif
                    @if($business->website)<a class="button button-primary" href="{{ $business->website }}" rel="nofollow ugc">Visit website <x-icon name="arrow" size="16"/></a>@endif
                    @if($business->phone)<div><strong>Phone</strong><p>{{ $business->phone }}</p></div>@endif
                    @if($business->public_email)<a class="button button-outline" href="mailto:{{ $business->public_email }}">Email business</a>@endif
                </section>
                @if($claimed && !$managesProfile && \App\Support\MessagingExtensions::enabled() && \App\Support\BusinessInquirySettings::accepts($business, false) && auth()->check() && auth()->user()->hasVerifiedEmail())
                    <x-inquiry-form :path="'/businesses/'.$business->id.'/message'" label="Message business"/>
                @endif
                @if($claimed && \App\Support\BusinessDisputes::enabled())
                    <section class="panel form-stack"><h2>Ownership concerns</h2><p class="field-note">If you believe this profile has been claimed incorrectly, request private independent review.</p>
                        @auth<a class="text-link" href="/businesses/{{ $business->slug }}/dispute">Raise an ownership dispute</a>@else<a class="text-link" href="{{ request()->attributes->get('site')->isNetwork() ? '/login' : '/auth/start' }}">Sign in to request review</a>@endauth
                    </section>
                @endif
                @if(!$claimed)
                    <section class="panel form-stack">
                        <h2>Own this business?</h2><p class="field-note">Request verified management access to update this profile.</p>
                        @auth
                            <details @if($errors->any()) open @endif><summary>Request ownership</summary>
                                <form class="form-stack mt-3" method="post" action="/businesses/{{ $business->slug }}/claim">
                                    @csrf
                                    <label>Business contact email<input type="email" name="contact_email" required maxlength="254" value="{{ old('contact_email') }}"></label>
                                    <label>How can we verify ownership?<textarea name="evidence" required minlength="30" maxlength="2400" rows="4">{{ old('evidence') }}</textarea><small class="field-note">Describe evidence you can share with reviewers. Keep private documents and passwords out of this form.</small></label>
                                    <button class="button button-primary">Request a claim</button>
                                </form>
                            </details>
                        @else<a href="{{ request()->attributes->get('site')->isNetwork() ? '/login' : '/auth/start' }}" class="text-link">Sign in to claim this listing <x-icon name="arrow" size="16"/></a>@endauth
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.site>
