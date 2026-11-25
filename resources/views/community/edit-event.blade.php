<x-layouts.site title="Edit event">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="/account/events">My events</a><h1>Edit event</h1><p>Correct an upcoming occurrence in {{ $item->location->name }}. Changes return the event to moderation; unchanged submissions retain their status.</p></div></div>
        <form method="post" action="/account/events/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            <input type="hidden" name="version" value="{{ $item->contentVersion() }}">
            <label>Title<input name="title" required maxlength="140" value="{{ old('title', $item->title) }}"></label>
            <label>Category<select name="category" required>@foreach(\App\Support\Community::EVENTS as $id => $label)<option value="{{ $id }}" @selected(old('category', $item->category) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Venue<input name="venue" required maxlength="180" value="{{ old('venue', $item->venue) }}"></label>
            <label>Organizer<input name="organizer" required maxlength="150" value="{{ old('organizer', $item->organizer) }}"></label>
            <div class="form-grid">
                <label>Starts (Manitoba time)<input type="datetime-local" name="starts_at" required value="{{ old('starts_at', $item->localStart()->format('Y-m-d\\TH:i')) }}"></label>
                <label>Ends (Manitoba time)<input type="datetime-local" name="ends_at" value="{{ old('ends_at', $item->localEnd()?->format('Y-m-d\\TH:i')) }}"></label>
            </div>
            <label>Event or tickets link<input type="url" name="event_url" maxlength="1024" value="{{ old('event_url', $item->event_url) }}"></label>
            <label>Description<textarea name="body" required minlength="10" maxlength="5000" rows="7">{{ old('body', $item->body) }}</textarea></label>
            <div class="inline-actions"><button class="button button-primary">Save event</button><a class="button button-outline" href="/account/events">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
