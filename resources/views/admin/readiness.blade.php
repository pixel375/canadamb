<x-layouts.admin title="Launch readiness">
    <div class="page-intro"><div><p class="section-label">Operations</p><h1>Launch readiness</h1><p>Read-only configuration indicators and outstanding acceptance checks.</p></div><a class="button button-outline" href="/admin">Network overview</a></div>
    <p class="notice">Configuration is not proof of service health or authorization to launch. This page cannot change operational settings.</p>
    <section class="panel admin-overview-section"><h2>Current configuration</h2>
        @foreach($checks as $check)<article class="item-row readiness-row"><div><h3>{{ $check['label'] }}</h3><p class="field-note">{{ $check['note'] }}</p></div><strong>{{ $check['value'] }}</strong></article>@endforeach
        <article class="item-row readiness-row"><div><h3>Unverified accounts</h3><p class="field-note">Accounts still requiring verification.</p></div><strong>{{ $unverified }}</strong></article>
    </section>
    <section class="panel form-stack mt-4"><h2>Staged feature inventory</h2><p class="field-note">Read-only migration history and schema checks. A schema match does not approve activation, retention or public use.</p>
        @foreach($staged as $feature)
            <article class="item-row readiness-row"><div><h3>{{ $feature['label'] }}</h3><p>{{ $feature['state'] }} · {{ $feature['configured'] ? 'Feature switch on' : 'Feature switch off' }}</p><p class="field-note">{{ $feature['note'] }}</p></div>
                @if($feature['configured'] && !$feature['ready'])<strong>Configuration mismatch</strong>@else<strong>{{ $feature['ready'] ? 'Schema recorded' : 'Review required' }}</strong>@endif
            </article>
        @endforeach
    </section>
    <section class="panel form-stack mt-4"><h2>Separate acceptance required</h2><ul class="help-guidelines"><li>Real-device authentication and Android standalone installation.</li><li>Verified outgoing mail and monitored support mailbox.</li><li>Broader content reporting and account lifecycle controls.</li><li>Privacy policy, terms, retention and moderation procedures.</li><li>Current backup and restore evidence, scheduler health and external access checks.</li><li>Explicit approval before public registration and removal of prelaunch access controls.</li></ul><p class="field-note">None of these items is marked complete automatically.</p></section>
</x-layouts.admin>
