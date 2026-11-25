<x-layouts.admin title="Launch readiness">
    <div class="page-intro"><div><p class="section-label">Operations</p><h1>Launch readiness</h1><p>Read-only configuration indicators and outstanding acceptance checks.</p></div><a class="button button-outline" href="/admin">Network overview</a></div>
    <p class="notice">Configuration is not proof of service health or authorization to launch. This page cannot change operational settings.</p>
    <section class="panel admin-overview-section"><h2>Current configuration</h2>
        @foreach($checks as $check)<article class="item-row readiness-row"><div><h3>{{ $check['label'] }}</h3><p class="field-note">{{ $check['note'] }}</p></div><strong>{{ $check['value'] }}</strong></article>@endforeach
        <article class="item-row readiness-row"><div><h3>Unverified accounts</h3><p class="field-note">Accounts still requiring verification.</p></div><strong>{{ $unverified }}</strong></article>
    </section>
    <section class="panel form-stack mt-4"><h2>Separate acceptance required</h2><ul class="help-guidelines"><li>Real-device authentication and Android standalone installation.</li><li>Verified outgoing mail and monitored support mailbox.</li><li>Administrative MFA, broader content reporting and account lifecycle controls.</li><li>Privacy policy, terms, retention and moderation procedures.</li><li>Current backup and restore evidence, scheduler health and external access checks.</li><li>Explicit approval before public registration and removal of prelaunch access controls.</li></ul><p class="field-note">None of these items is marked complete automatically.</p></section>
</x-layouts.admin>
