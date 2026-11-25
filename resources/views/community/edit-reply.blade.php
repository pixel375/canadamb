<x-layouts.site title="Edit my reply">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/replies">My replies</a><h1>Edit my reply</h1><p>{{ $item->thread->title }}</p></div></div>
        <form class="panel form-stack" method="post" action="/account/replies/{{ $item->id }}">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ $item->contentVersion() }}">
            <p class="notice">Changed replies are removed from public view until approved again.</p>
            <label>Reply<textarea name="body" minlength="5" maxlength="3000" rows="6" required data-character-count>{{ old('body', $item->body) }}</textarea><small class="field-note" data-character-feedback></small></label>
            <div class="inline-actions"><button class="button button-primary">Save reply</button><a class="button button-outline" href="/account/replies">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
