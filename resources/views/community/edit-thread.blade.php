<x-layouts.site title="Edit discussion">
    <div class="container page-shell listing-editor">
        <div class="page-intro"><div><a class="text-link" href="/account/discussions">My discussions</a><h1>Edit discussion</h1><p>{{ $item->location?->name ?? 'Manitoba' }} · {{ ucfirst($item->status) }}</p></div></div>
        <p class="notice">Changed discussions return to moderation. Published replies will be hidden while the discussion is under review and restored if it is approved again. Location cannot be changed.</p>
        <form method="post" action="/account/discussions/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ old('version', $item->contentVersion()) }}">
            <label>Discussion title<input name="title" required maxlength="140" value="{{ old('title', $item->title) }}"></label>
            <label>Category<select name="category" required>@foreach(\App\Support\Community::FORUMS as $key => $label)<option value="{{ $key }}" @selected(old('category', $item->category) === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Your post<textarea name="body" data-character-count required minlength="10" maxlength="5000" rows="8">{{ old('body', $item->body) }}</textarea><small class="field-note" data-character-feedback></small></label>
            @if(old('version') && old('version') !== $item->contentVersion())
                <p role="alert">This discussion changed while the form was open. <a href="/account/discussions/{{ $item->id }}/edit">Reload editor</a> before saving.</p>
            @endif
            <button class="button button-primary">Save for review</button>
        </form>
    </div>
</x-layouts.site>
