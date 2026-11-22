<x-layouts.site title="Edit discussion">
    <div class="container page-shell narrow">
        <div class="page-intro">
            <div>
                <a class="text-link" href="/account/discussions/{{ $item->id }}/preview">Manage discussion</a>
                <h1>Edit discussion</h1>
                @if($item->status === 'approved')
                    <p>Changes may require another review before your discussion appears again.</p>
                @endif
            </div>
        </div>
        <form method="post" action="/account/discussions/{{ $item->id }}" class="panel form-stack">
            @csrf
            @method('PUT')
            <input type="hidden" name="version" value="{{ $version }}">
            <p>Location · {{ $item->location?->name ?? 'Manitoba-wide' }}</p>
            <label>Title<input name="title" required maxlength="140" value="{{ $draft['title'] }}"></label>
            <label>Category
                <select name="category" required>
                    @foreach(\App\Support\Community::FORUMS as $id => $label)
                        <option value="{{ $id }}" @selected($draft['category'] === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Post<textarea name="body" required minlength="10" maxlength="5000" rows="7">{{ $draft['body'] }}</textarea></label>
            <div><button class="button button-primary" type="submit">Save changes</button></div>
        </form>
    </div>
</x-layouts.site>
