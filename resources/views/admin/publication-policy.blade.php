<x-layouts.admin title="Moderation policy">
    <div class="page-intro"><div><p class="section-label">Content &amp; safety</p><h1>Moderation policy</h1><p>Choose which new submissions require manual approval across the network.</p></div></div>
    <p class="notice">Automatic approval is not a spam or safety check. Existing pending content and edits are not auto-approved. Jobs, real estate, business claims, news and payments remain under separate review workflows. Calendar events and their linked discussions have independent policies. Report and removal tools remain available.</p>
    <p class="field-note">Individual community configurations may enforce stricter manual review for content associated with that location. <a class="text-link" href="/admin/communities">Manage communities</a>.</p>
    <form class="panel form-stack" method="post" action="/admin/publication-policy">@csrf
        <input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}">
        @foreach(\App\Support\PublicationPolicy::TYPES as $type => $label)
            <fieldset class="form-stack"><legend>{{ $label }}</legend>
                <p class="field-note">Live: {{ \App\Support\PublicationPolicy::MODES[$published['publication_policy'][$type] ?? 'manual'] }}</p>
                <label>Draft policy<select name="policy[{{ $type }}]">@foreach(\App\Support\PublicationPolicy::MODES as $key => $mode)<option value="{{ $key }}" @selected(($draft['publication_policy'][$type] ?? 'manual') === $key)>{{ $mode }}</option>@endforeach</select></label>
            </fieldset>
        @endforeach
        <label class="checkbox-line"><input type="checkbox" name="confirm_policy" value="1" required> I understand the moderation implications of these settings.</label>
        <button class="button button-primary">Save moderation draft</button>
    </form>
    <section class="panel form-stack"><h2>Publish deliberately</h2><p>All pending global configuration changes are published together. Review the draft before publishing; no changes are live yet.</p><a class="button button-outline" href="/admin/settings">Review and publish network settings</a></section>
</x-layouts.admin>
