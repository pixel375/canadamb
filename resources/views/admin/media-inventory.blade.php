<x-layouts.admin title="Media storage inventory">
    <div class="page-intro"><div><a class="text-link" href="/admin/readiness">Launch readiness</a><h1>Media storage inventory</h1><p>Read-only, bounded observations. No file paths, identifiers or file contents are returned.</p></div></div>
    <p class="notice">This is a sampled inspection, not a complete backup or deletion plan. Unreferenced files older than 48 hours are candidates for further investigation, never automatic deletion.</p>
    <form method="get" action="/admin/media-inventory" class="panel saved-search mb-4">
        <label>Inspection limit per storage area<select name="limit">@foreach([50,100,250] as $size)<option value="{{ $size }}" @selected($size === $limit)>{{ $size }}</option>@endforeach</select></label>
        <button class="button button-primary">Inspect again</button>
    </form>
    <div class="form-stack">
        @foreach(['marketplace' => 'Marketplace photos', 'private_properties' => 'Private property photos', 'private_messages' => 'Private message attachments'] as $key => $label)
            @php($result = $results[$key])
            <section class="panel form-stack"><h2>{{ $label }}</h2>
                @if(!$result['schema_available'])
                    <p>Schema unavailable. No storage inspection performed.</p>
                @elseif($result['directory_symlink'])
                    <p>Unexpected directory symlink. Inspection stopped.</p>
                @else
                    @foreach(['rows_examined' => 'Database rows examined', 'missing_files' => 'Missing file references', 'invalid_references' => 'Invalid references', 'files_examined' => 'Filesystem entries examined', 'old_unreferenced_files' => 'Older unreferenced files', 'unexpected_entries' => 'Unexpected entries'] as $field => $description)
                        <div class="item-row"><span>{{ $description }}</span><strong>{{ $result[$field] }}</strong></div>
                    @endforeach
                    @if($result['rows_truncated'] || $result['files_truncated'])<p class="field-note">Inspection limit reached. Results are incomplete.</p>@endif
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.admin>
