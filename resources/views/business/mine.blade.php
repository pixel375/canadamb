<x-layouts.site title="My businesses">
    <div class="container page-shell">
        <div class="page-intro"><div><a class="text-link" href="/account/profile">My account</a><h1>My businesses</h1><p>Manage business profiles, announcements and hiring.</p></div><a class="button button-primary" href="/account/businesses/new"><x-icon name="plus" size="18"/> Add a business</a></div>
        @if(\App\Support\BusinessAccess::enabled())<p class="mb-4"><a class="button button-outline" href="/account/business-access">Invitations and ownership transfers</a></p>@endif
        @if(\App\Support\BusinessDisputes::enabled())<p class="mb-4"><a class="button button-outline" href="/account/business-disputes">My ownership disputes</a></p>@endif
        <?php $site = request()->attributes->get('site'); ?>
        <section aria-label="Managed business profiles" class="seller-listing-grid">
            @forelse($businesses as $business)
                <?php $role = $business->members->first()?->pivot->role; ?>
                <article class="panel seller-listing-card">
                    <div class="seller-listing-top"><span class="seller-listing-thumbnail"><x-icon name="building" size="28"/></span>
                        <div class="member-listing-info"><span class="member-listing-status" data-status="{{ $business->status }}">{{ ucfirst($business->status) }}</span><h2>{{ $business->name }}</h2><p>{{ \App\Support\BusinessDirectory::CATEGORIES[$business->category] ?? $business->category }} · {{ ucfirst($role ?? 'member') }}</p></div>
                    </div>
                    <p class="field-note">{{ $business->locations->filter(fn ($branch) => $branch->isOpen())->count() }} open {{ \Illuminate\Support\Str::plural('branch', $business->locations->filter(fn ($branch) => $branch->isOpen())->count()) }} · {{ $business->locations->filter(fn ($branch) => $branch->isOpen())->pluck('location.name')->unique()->implode(', ') }}</p>
                    <p class="seller-lifecycle-note">{{ $business->status === 'active' ? 'Profile approved. Manage your business information and publish updates.' : 'This profile is not currently public. Open the profile to manage its details.' }}</p>
                    <div class="inline-actions seller-listing-actions">
                        <a class="button button-primary" href="/account/businesses/{{ $business->id }}">Manage profile</a>
                        <a class="button button-outline" href="/account/businesses/{{ $business->id }}/team">Team</a>
                        @if($business->status === 'active' && in_array($role, ['owner','manager'], true) && app(\App\Support\JobBoard::class)->enabled($site))
                            <a class="button button-outline" href="/account/businesses/{{ $business->id }}/jobs"><x-icon name="briefcase" size="17"/> Manage jobs</a>
                        @endif
                        @if($business->status === 'active' && app(\App\Support\BusinessDirectory::class)->enabled($site) && $business->locations->contains(fn ($branch) => $branch->isOpen() && ($site->isNetwork() || $branch->location_id === $site->location_id)))
                            <a class="text-link" href="/businesses/{{ $business->slug }}">View public profile <x-icon name="arrow" size="16"/></a>
                        @endif
                    </div>
                </article>
            @empty
                <div class="panel market-results-empty"><x-icon name="building" size="32"/><h2>You do not manage any business profiles yet.</h2><p>Add your business or find an existing profile to request a claim.</p><div class="inline-actions"><a class="button button-primary" href="/account/businesses/new">Add a business</a><a class="button button-outline" href="/businesses">Find my business</a></div></div>
            @endforelse
        </section>
        {{ $businesses->links() }}
        @if($claims->isNotEmpty())
            <section class="panel mt-4"><h2>Claim requests</h2><p class="field-note">Ownership requests are reviewed before management access is granted.</p>
                @foreach($claims as $claim)<div class="item-row"><div><h3>{{ $claim->name }}</h3><p>Submitted {{ \Illuminate\Support\Carbon::parse($claim->created_at)->format('M j, Y') }}</p></div><div class="form-stack"><span class="member-listing-status" data-status="{{ $claim->status }}">{{ ucfirst($claim->status) }}</span>
                    @if($claim->status === 'pending')<form method="post" action="/account/business-claims/{{ $claim->id }}/withdraw">@csrf<button class="button button-outline">Withdraw claim</button></form>@endif
                    </div></div>@endforeach
                {{ $claims->links() }}
            </section>
        @endif
    </div>
</x-layouts.site>
