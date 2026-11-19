<x-layouts.site title="Start a discussion">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Start a discussion</h1><p>New discussions appear after moderation.</p></div></div>
        <form method="post" action="/community" class="panel form-stack">@csrf
            <label>Title<input name="title" required maxlength="140" value="{{ old('title') }}"></label>
            <label>Category<select name="category" required><option value="">Choose a category</option>@foreach(\App\Support\Community::FORUMS as $id => $label)<option value="{{ $id }}" @selected(old('category') === $id)>{{ $label }}</option>@endforeach</select></label>
            <x-community-location :places="$places" :allow-network="true"/>
            <label>Post<textarea name="body" required minlength="10" maxlength="5000" rows="7">{{ old('body') }}</textarea></label>
            <div><button class="button button-primary">Submit for review</button></div>
        </form>
    </div>
</x-layouts.site>
