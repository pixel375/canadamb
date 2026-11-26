<x-layouts.admin title="Theme & appearance">
    <div class="page-intro"><div><h1>Theme & appearance</h1><p>Published configuration {{ $state->version }}{{ $state->draft_id ? ' · Draft available' : '' }}</p></div></div>
    @php($presetPalettes = array_combine(array_keys($presets), $palettes))
    <form class="panel form-stack" method="post" action="/admin/theme" x-data="themeEditor(@js($presetPalettes), @js($theme['palette']), @js($theme['preset']))">
        @csrf
        <input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}">
        <label>Theme preset<select name="preset" x-model="selected" x-on:change="preset()">
            @foreach($presets as $key => $label)<option value="{{ $key }}" @selected($theme['preset'] === $key)>{{ $label }}</option>@endforeach
        </select></label>
        <p class="field-note">Choose a network palette or customize the light and dark colors separately. Local community accents remain independent.</p>
        <div class="theme-samples">
            @foreach(['light', 'dark'] as $mode)
                <div class="theme-sample" x-bind:style="preview('{{ $mode }}')" style="@foreach($theme['palette'][$mode] as $token => $value)--sample-{{ str_replace('_', '-', $token) }}:{{ $value }};@endforeach">
                    <div><h3>{{ ucfirst($mode) }} preview</h3><p>Marketplace · Local community</p><span>Post a listing</span></div>
                </div>
            @endforeach
        </div>
        @foreach(['light', 'dark'] as $mode)
            <fieldset class="form-grid">
                <legend class="font-bold mb-2">{{ ucfirst($mode) }} colors</legend>
                @foreach($tokens as $key => $label)
                    <label>{{ $label }}<span class="color-row">
                        <input type="color" aria-label="{{ $label }} color picker" x-bind:value="current.{{ $mode }}.{{ $key }}" x-on:input="modify('{{ $mode }}', '{{ $key }}', $event.target.value)">
                        <input type="text" name="palette[{{ $mode }}][{{ $key }}]" value="{{ $theme['palette'][$mode][$key] }}" pattern="#[a-fA-F0-9]{6}" maxlength="7" required x-bind:value="current.{{ $mode }}.{{ $key }}" x-on:input="modify('{{ $mode }}', '{{ $key }}', $event.target.value)">
                    </span></label>
                @endforeach
            </fieldset>
            <p class="field-note" x-show="contrast('{{ $mode }}')" style="display:none">{{ ucfirst($mode) }} preview has text contrast below 4.5:1.</p>
        @endforeach
        @if($warnings)<ul class="notice notice-error">@foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>@endif
        <div class="inline-actions"><button class="button button-primary" name="action" value="draft">Save draft</button><button class="button button-outline" type="button" x-on:click="selected = 'blue'; preset()">Reset to defaults</button></div>
    </form>
    <p class="notice">Publishing or restoring a configuration applies every included setting, not just colors. <a class="text-link" href="/admin/settings">Review pending configuration changes</a> before publishing.</p>
    <div class="panel inline-actions">
        @if($state->draft_id)<form action="/admin/theme/publish" method="post">@csrf<input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}"><button class="button button-primary">Publish across the network</button></form>@endif
        @if($state->previous_id)<form action="/admin/theme/rollback" method="post">@csrf<input type="hidden" name="configuration_version" value="{{ \App\Support\NetworkSettings::stateVersion($state) }}"><button class="button button-outline">Restore previous published configuration</button></form>@endif
        @if(!$state->draft_id && !$state->previous_id)<p>Save a draft to publish a new theme.</p>@endif
    </div>
</x-layouts.admin>
