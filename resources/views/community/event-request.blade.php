@php
    $refill = old('thread_version') === $threadVersion && (!$item || old('version') === $version);
@endphp
<x-layouts.site :title="$item ? 'Edit event request' : 'Request event listing'">
    <div class="container page-shell narrow">
        <div class="page-intro"><div><h1>{{ $item ? 'Edit event request' : 'Request event listing' }}</h1>
            <p><a class="text-link" href="/discussions/{{ $source->id }}">{{ $source->title }}</a> provides the event description and replies.</p></div></div>
        <form class="panel form-stack" method="post" action="{{ $item ? '/account/events/'.$item->id : '/discussions/'.$source->id.'/event' }}">
            @csrf
            <input type="hidden" name="thread_version" value="{{ $threadVersion }}">
            @if($item)
                @method('PUT')
                <input type="hidden" name="version" value="{{ $version }}">
                <p>Location · {{ $item->location->name }}</p>
            @elseif($source->location)
                <p>Location · {{ $source->location->name }}</p>
            @else
                <label>Event location<select name="location_id" required><option value="">Choose a Manitoba community</option>
                    @foreach($places as $place)
                        <option value="{{ $place->id }}" @selected($refill && (string) $place->id === old('location_id'))>{{ $place->name }}</option>
                    @endforeach
                </select></label>
            @endif
            <x-event-metadata :item="$item" :use-old="$refill"/>
            @if($item)<p class="field-note">Changes and resubmissions enter manual review.</p>@endif
            <div class="inline-actions">
                <button class="button button-primary">{{ $item ? 'Save event request' : 'Submit event request' }}</button>
                <a class="text-link" href="{{ $item ? '/account/events' : '/discussions/'.$source->id }}">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.site>
