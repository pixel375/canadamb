<x-layouts.site title="Start a discussion">
    <div class="container page-shell narrow">
        <nav class="mb-4" aria-label="Breadcrumb"><a class="text-link" href="/discussions">Discussions</a></nav>
        <div class="page-intro"><div><h1>Start a discussion</h1><p>Your post may be held for moderation.</p></div></div>
        <form method="post" action="/discussions" class="panel form-stack">@csrf
            <label>Title<input name="title" required maxlength="140" value="{{ is_string(old('title')) ? old('title') : '' }}"></label>
            <label>Category<select name="category" required><option value="">Choose a category</option>@foreach(\App\Support\Community::FORUMS as $id => $label)<option value="{{ $id }}" @selected(old('category') === $id)>{{ $label }}</option>@endforeach</select></label>
            <x-community-location :places="$places" :allow-network="true"/>
            <label>Post<textarea name="body" required minlength="10" maxlength="5000" rows="7">{{ is_string(old('body')) ? old('body') : '' }}</textarea></label>
            <div><button class="button button-primary">Submit discussion</button></div>
        </form>
    </div>
</x-layouts.site>
