<?php
    $locationParams = request()->attributes->get('site')->isNetwork() && $suggestedLocation ? ['location' => $suggestedLocation->slug] : [];
?>
<x-layouts.site :title="$postType === 'event' ? 'Submit an event' : 'Start a discussion'">
    <div class="container page-shell narrow">
        <a class="text-link mb-4" href="/community{{ $locationParams ? '?'.http_build_query($locationParams) : '' }}"><x-icon name="arrow" size="16"/> Back to Discussions</a>
        <div class="page-intro">
            <div>
                <h1>{{ $postType === 'event' ? 'Submit an event' : 'Start a discussion' }}</h1>
                <p>{{ $postType === 'event' ? 'Share an upcoming local event. Events appear after moderation.' : 'Create a conversation for your community. Posts appear after moderation.' }}</p>
            </div>
        </div>
        <nav class="discussion-composer-switch" aria-label="Choose post type">
            <a href="/community/new{{ $locationParams ? '?'.http_build_query($locationParams) : '' }}" @if($postType === 'discussion') aria-current="page" @endif><x-icon name="discussions" size="19"/> Discussion</a>
            @if($eventsEnabled)<a href="/community/new?{{ http_build_query(['type' => 'event'] + $locationParams) }}" @if($postType === 'event') aria-current="page" @endif><x-icon name="calendar" size="19"/> Event request</a>@endif
        </nav>
        @if($postType === 'event')
            <form method="post" action="/events" class="panel form-stack">
                @csrf
                <label>Event title<input name="title" required maxlength="140" value="{{ old('title') }}"></label>
                <label>Event category<select name="category" required><option value="">Choose a category</option>@foreach(\App\Support\Community::EVENTS as $key => $label)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>@endforeach</select></label>
                <x-community-location :places="$places" :suggested-location="$suggestedLocation"/>
                <div class="form-grid">
                    <label>Venue<input name="venue" required maxlength="180" value="{{ old('venue') }}"></label>
                    <label>Organizer<input name="organizer" required maxlength="150" value="{{ old('organizer') }}"></label>
                </div>
                <div class="form-grid">
                    <label>Starts (Manitoba time)<input type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}"></label>
                    <label>Ends (Manitoba time)<input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}"></label>
                </div>
                <label>Event or tickets link (optional)<input type="url" name="event_url" maxlength="1024" value="{{ old('event_url') }}"></label>
                <label>Event details<textarea data-character-count name="body" required minlength="10" maxlength="5000" rows="7">{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
                <div class="discussion-form-footer"><p>Events are reviewed before appearing in the calendar. @if(\App\Support\EventDiscussions::enabled()) A companion discussion will also be submitted for review.@endif</p><button class="button button-primary" type="submit">Submit event for review</button></div>
            </form>
        @else
            <form method="post" action="/community" class="panel form-stack">
                @csrf
                <label>Discussion title<input name="title" required maxlength="140" value="{{ old('title') }}"></label>
                <label>Category<select name="category" required><option value="">Choose a category</option>@foreach(\App\Support\Community::FORUMS as $key => $label)<option value="{{ $key }}" @selected(old('category', $suggestedCategory) === $key)>{{ $label }}</option>@endforeach</select></label>
                <x-community-location :places="$places" :suggested-location="$suggestedLocation" :allow-network="true"/>
                <label>Your post<textarea data-character-count name="body" required minlength="10" maxlength="5000" rows="7">{{ old('body') }}</textarea><small class="field-note" data-character-feedback></small></label>
                <div class="discussion-form-footer"><p>Discussions are reviewed before appearing publicly.</p><button class="button button-primary" type="submit">Submit discussion for review</button></div>
            </form>
        @endif
    </div>
</x-layouts.site>
