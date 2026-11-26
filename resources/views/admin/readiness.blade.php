<x-layouts.admin title="Launch readiness">
    <div class="page-intro"><div><p class="section-label">Operations</p><h1>Launch readiness</h1><p>Read-only configuration indicators and outstanding acceptance checks.</p></div><a class="button button-outline" href="/admin">Network overview</a></div>
    <p class="notice">Configuration is not proof of service health or authorization to launch. This page cannot change operational settings.</p>
    <section class="panel admin-overview-section"><h2>Current configuration</h2>
        @foreach($checks as $check)<article class="item-row readiness-row"><div><h3>{{ $check['label'] }}</h3><p class="field-note">{{ $check['note'] }}</p></div><strong>{{ $check['value'] }}</strong></article>@endforeach
        <article class="item-row readiness-row"><div><h3>Unverified accounts</h3><p class="field-note">Accounts still requiring verification.</p></div><strong>{{ $unverified }}</strong></article>
    </section>
    <section class="panel form-stack mt-4"><h2>Staged feature inventory</h2><p class="field-note">Read-only migration history and schema checks. A schema match does not approve activation, retention or public use.</p>
        <p class="field-note">{{ collect($staged)->where('configured', true)->count() }} enabled switches · {{ collect($staged)->where('ready', true)->count() }} ready including dependencies · {{ collect($staged)->pluck('migration')->unique()->count() }} proposed migrations</p>
        @foreach($staged as $feature)
            <article class="item-row readiness-row"><div><h3>{{ $feature['label'] }}</h3><p>{{ $feature['state'] }} · {{ $feature['configured'] ? 'Feature switch on' : 'Feature switch off' }}</p><p class="field-note">{{ $feature['note'] }}</p>
                @if($feature['problems'])<details><summary>Schema and dependency details</summary>@foreach($feature['problems'] as $problem)<p class="field-note">{{ $problem }}</p>@endforeach</details>@endif</div>
                @if($feature['configured'] && !$feature['ready'])<strong>Configuration mismatch</strong>@else<strong>{{ $feature['ready'] ? 'Schema recorded' : 'Review required' }}</strong>@endif
            </article>
        @endforeach
    </section>
    <section class="panel form-stack mt-4"><h2>Queue observations</h2>
        <p class="field-note">Aggregate database observations only. A quiet queue does not prove the scheduler is running. No payloads or exceptions are exposed.</p>
        @if($queue['database_queue'])
            @foreach(['ready' => 'Ready jobs', 'delayed' => 'Delayed jobs', 'reserved' => 'Reserved jobs', 'retryable_reservations' => 'Reservations past retry window', 'oldest_ready_seconds' => 'Oldest ready job (seconds)', 'failed' => 'Recorded failed jobs'] as $key => $label)
                <article class="item-row readiness-row"><h3>{{ $label }}</h3><strong>{{ $queue[$key] ?? 'Unavailable' }}</strong></article>
            @endforeach
        @else
            <p>Database queue observations unavailable.</p>
        @endif
    </section>
    @can('manage-theme')
        <section class="panel form-stack mt-4"><h2>Media storage observations</h2><p class="field-note">Inspect bounded aggregate media counts, missing files and potential orphan candidates. The report does not reveal file paths or change stored data.</p><a class="button button-outline" href="/admin/media-inventory">Inspect media storage</a></section>
    @endcan
    <section class="panel form-stack mt-4"><h2>Separate acceptance required</h2><ul class="help-guidelines"><li>Real-device authentication and Android standalone installation.</li><li>Verified outgoing mail and monitored support mailbox.</li><li>Broader content reporting and account lifecycle controls.</li><li>Privacy policy, terms, retention and moderation procedures.</li><li>Current backup and restore evidence, scheduler health and external access checks.</li><li>Explicit approval before public registration and removal of prelaunch access controls.</li></ul><p class="field-note">None of these items is marked complete automatically.</p></section>
</x-layouts.admin>
