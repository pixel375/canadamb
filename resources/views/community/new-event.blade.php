<x-layouts.site title="Submit an event">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>Submit an event</h1><p>New events appear after moderation.</p></div></div>
        <form method="post" action="/events" class="panel form-stack">@csrf
            <label>Title<input name="title" required maxlength="140" value="{{ old('title') }}"></label>
            <label>Category<select name="category" required><option value="">Choose a category</option>@foreach(\App\Support\Community::EVENTS as $id => $label)<option value="{{ $id }}" @selected(old('category') === $id)>{{ $label }}</option>@endforeach</select></label>
            <x-community-location :places="$places" :suggested-location="$suggestedLocation"/>
            <label>Venue<input name="venue" required maxlength="180" value="{{ old('venue') }}"></label>
            <label>Organizer<input name="organizer" required maxlength="150" value="{{ old('organizer') }}"></label>
            <div class="form-grid">
                <label>Starts (Manitoba time)<input type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}"></label>
                <label>Ends (Manitoba time)<input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}"></label>
            </div>
            <x-event-repeat-fields/>
            <label>Event or tickets link<input type="url" name="event_url" maxlength="1024" value="{{ old('event_url') }}"></label>
            <label>Description<textarea data-character-count name="body" required minlength="10" maxlength="5000" rows="7">{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
            <div><button class="button button-primary">Submit for review</button></div>
        </form>
    </div>
</x-layouts.site>
