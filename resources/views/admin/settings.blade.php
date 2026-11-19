<x-layouts.admin title="Global settings">
    <div class="page-intro"><div><h1>Global settings</h1><p>Published version {{ $state->version }}{{ $state->draft_id ? ' · Draft available' : '' }}</p></div></div>
    <form method="post" action="/admin/settings" class="panel form-stack">
        @csrf
        <h2>Module availability</h2>
        @foreach($modules as $key => $label)
            <fieldset class="form-stack pb-3">
                <legend class="font-bold">{{ $label }}</legend>
                <label class="checkbox"><input type="checkbox" name="modules[{{ $key }}]" value="1" @checked($settings['modules'][$key] ?? false)> Available network-wide</label>
                <label class="checkbox"><input type="checkbox" name="overrides[{{ $key }}]" value="1" @checked(in_array($key, $settings['permitted_module_overrides'], true))> Allow local portals to hide this module</label>
            </fieldset>
        @endforeach
        <div><button class="button button-primary">Save draft</button></div>
    </form>
    @if($state->draft_id)
        <form method="post" action="/admin/settings/publish" class="panel inline-actions">@csrf
            <p>This publishes pending appearance and module settings together.</p>
            <button class="button button-primary">Publish network settings</button>
        </form>
    @endif
</x-layouts.admin>
