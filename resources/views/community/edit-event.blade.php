<x-layouts.site title="Edit event">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><a class="text-link" href="{{ $returnUrl }}">My events</a><h1>Edit event</h1><p>Correct an upcoming occurrence in {{ $item->location->name }}. Changes return the event to moderation; unchanged submissions retain their status.</p></div></div>
        @if($linkedThread)<p class="notice">Title and description changes also update the linked discussion and return it to independent moderation. Existing replies and followers are kept. Date, venue and other event-only changes do not reset discussion approval.</p>@endif
        <form method="post" action="/account/events/{{ $item->id }}" class="panel form-stack">@csrf @method('PUT')
            @if($series)<input type="hidden" name="series" value="{{ $series }}">@endif
            @foreach($returnFilters as $key => $value)
                @if(filled($value))<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endif
            @endforeach
            <input type="hidden" name="version" value="{{ old('version', $item->contentVersion()) }}">
            @if($linkedThread)<input type="hidden" name="thread_version" value="{{ old('thread_version', $linkedThread->contentVersion()) }}">@endif
            <label>Title<input name="title" required maxlength="140" value="{{ old('title', $item->title) }}"></label>
            <label>Category<select name="category" required>@foreach(\App\Support\Community::EVENTS as $id => $label)<option value="{{ $id }}" @selected(old('category', $item->category) === $id)>{{ $label }}</option>@endforeach</select></label>
            <label>Venue<input name="venue" required maxlength="180" value="{{ old('venue', $item->venue) }}"></label>
            <label>Organizer<input name="organizer" required maxlength="150" value="{{ old('organizer', $item->organizer) }}"></label>
            <x-event-schedule-fields :item="$item"/>
            <label>Event or tickets link<input type="url" name="event_url" maxlength="1024" value="{{ old('event_url', $item->event_url) }}"></label>
            <label>Description<textarea name="body" required minlength="10" maxlength="5000" rows="7">{{ old('body', $item->body) }}</textarea></label>
            <div class="inline-actions"><button class="button button-primary">Save event</button><a class="button button-outline" href="{{ $returnUrl }}">Cancel</a></div>
        </form>
    </div>
</x-layouts.site>
