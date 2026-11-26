<x-layouts.admin title="Edit editorial draft">
    <div class="page-intro"><div><a class="text-link" href="/admin/news?status=draft">Editorial queue</a><h1>Edit editorial draft</h1><p>Save changes without publishing. Source identity and original submission identifiers are retained.</p></div></div>
    <form class="panel form-stack" method="post" action="/admin/news/{{ $item->id }}/draft">@csrf @method('PUT')
        <input type="hidden" name="version" value="{{ old('version', $item->reviewVersion()) }}">
        @if(old('version') && old('version') !== $item->reviewVersion())<p class="notice">This draft changed. <a href="/admin/news/{{ $item->id }}/edit">Reload editor</a> before saving.</p>@endif
        <p><a class="text-link" href="{{ $item->source_url }}" rel="noreferrer">{{ $item->source->name }}</a> · {{ $item->location?->name ?? 'Manitoba' }} · {{ $item->source_date->format('M j, Y') }}</p>
        <label>Headline<input name="title" maxlength="140" required value="{{ old('title', $item->title) }}"></label>
        <label>Summary<textarea name="body" minlength="20" maxlength="2500" rows="8" required>{{ old('body', $item->body) }}</textarea></label>
        <label>Category<select name="category">@foreach(\App\Support\News::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(old('category', $item->category) === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>Sensitive coverage<select name="sensitive"><option value="0" @selected(!old('sensitive', $item->sensitive))>Not marked</option><option value="1" @selected(old('sensitive', $item->sensitive))>Sensitive</option></select></label>
        <p class="field-note">Previously sensitive coverage remains sensitive. Safety and weather categories always require corroboration at publication.</p>
        <div class="inline-actions"><button class="button button-primary">Save without publication</button><a class="button button-outline" href="/admin/news/{{ $item->id }}/history">Revision history</a></div>
    </form>
</x-layouts.admin>
