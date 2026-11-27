<x-layouts.site title="Edit my reply">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="{{ $returnPath }}">{{ ($origin['from'] ?? null) === 'discussion' ? 'Back to discussion' : 'My replies' }}</a><h1>Edit my reply</h1><p>{{ $item->thread->title }}</p></div></div>
        <form class="panel form-stack" method="post" action="{{ \App\Support\LocationScope::detailUrl('/account/replies/'.$item->id) }}">@csrf @method('PUT')
            @if(($origin['from'] ?? null) === 'discussion')<input type="hidden" name="from" value="discussion"><input type="hidden" name="page" value="{{ $origin['page'] ?? 1 }}">@endif
            <input type="hidden" name="version" value="{{ old('version', $item->contentVersion()) }}">
            <p class="notice">Changed replies are removed from public view until approved again.</p>
            <label>Reply<textarea name="body" minlength="5" maxlength="3000" rows="6" required data-character-count>{{ old('body', $item->body) }}</textarea><small class="field-note" data-character-feedback></small></label>
            @if(old('version') && old('version') !== $item->contentVersion())
                <p role="alert">This reply changed while the form was open. <a href="{{ \App\Support\LocationScope::detailUrl('/account/replies/'.$item->id.'/edit', $origin) }}">Reload editor</a> before saving.</p>
            @endif
            <div class="inline-actions"><button class="button button-primary">Save reply</button><a class="button button-outline" href="{{ $returnPath }}">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
